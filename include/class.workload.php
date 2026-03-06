<?php
/*********************************************************************
    class.workload.php

    Workload estimation for ticket queues. Calculates queue position
    and estimated wait/start time based on historical service times,
    current queue depth, and agent capacity.

    Designed for repair-shop workflows where customers want to know
    when their ticket will be started.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

class WorkloadEstimator {

    /**
     * Get the number of open tickets ahead of the given ticket in its
     * department queue, ordered by creation date (FIFO).
     */
    static function getQueuePosition($ticket) {
        if (!$ticket || !$ticket->isOpen())
            return 0;

        $sql = 'SELECT COUNT(*) FROM ' . TICKET_TABLE . ' t'
            . ' JOIN ' . TICKET_STATUS_TABLE . ' s ON (s.id = t.status_id)'
            . ' WHERE s.state = "open"'
            . ' AND t.dept_id = ' . db_input($ticket->getDeptId())
            . ' AND t.created < ' . db_input($ticket->getCreateDate())
            . ' AND t.ticket_id != ' . db_input($ticket->getId());

        $row = db_fetch_row(db_query($sql));
        return $row ? (int) $row[0] : 0;
    }

    /**
     * Get the average service time (in hours) for closed tickets in a
     * department over the last 90 days. Falls back to all departments
     * if the department has insufficient data.
     *
     * Returns the average hours from ticket creation to closure.
     */
    static function getAvgServiceTime($deptId, $topicId = 0) {
        $cutoff = date('Y-m-d H:i:s', strtotime('-90 days'));

        // Try topic-specific average first
        if ($topicId) {
            $sql = 'SELECT AVG(TIMESTAMPDIFF(HOUR, t.created, t.closed)) AS avg_hours,'
                . ' COUNT(*) AS cnt'
                . ' FROM ' . TICKET_TABLE . ' t'
                . ' JOIN ' . TICKET_STATUS_TABLE . ' s ON (s.id = t.status_id)'
                . ' WHERE s.state = "closed"'
                . ' AND t.topic_id = ' . db_input($topicId)
                . ' AND t.dept_id = ' . db_input($deptId)
                . ' AND t.closed >= ' . db_input($cutoff)
                . ' AND t.closed IS NOT NULL';

            $row = db_fetch_row(db_query($sql));
            if ($row && $row[1] >= 5)
                return max(1, round((float) $row[0], 1));
        }

        // Fall back to department average
        $sql = 'SELECT AVG(TIMESTAMPDIFF(HOUR, t.created, t.closed)) AS avg_hours,'
            . ' COUNT(*) AS cnt'
            . ' FROM ' . TICKET_TABLE . ' t'
            . ' JOIN ' . TICKET_STATUS_TABLE . ' s ON (s.id = t.status_id)'
            . ' WHERE s.state = "closed"'
            . ' AND t.dept_id = ' . db_input($deptId)
            . ' AND t.closed >= ' . db_input($cutoff)
            . ' AND t.closed IS NOT NULL';

        $row = db_fetch_row(db_query($sql));
        if ($row && $row[1] >= 3)
            return max(1, round((float) $row[0], 1));

        // Fall back to system-wide average
        $sql = 'SELECT AVG(TIMESTAMPDIFF(HOUR, t.created, t.closed)) AS avg_hours,'
            . ' COUNT(*) AS cnt'
            . ' FROM ' . TICKET_TABLE . ' t'
            . ' JOIN ' . TICKET_STATUS_TABLE . ' s ON (s.id = t.status_id)'
            . ' WHERE s.state = "closed"'
            . ' AND t.closed >= ' . db_input($cutoff)
            . ' AND t.closed IS NOT NULL';

        $row = db_fetch_row(db_query($sql));
        if ($row && $row[1] >= 1)
            return max(1, round((float) $row[0], 1));

        // No historical data at all — use a safe default of 4 hours
        return 4.0;
    }

    /**
     * Count active agents (staff) in a department.
     */
    static function getActiveAgentCount($deptId) {
        $sql = 'SELECT COUNT(*) FROM ' . STAFF_TABLE
            . ' WHERE dept_id = ' . db_input($deptId)
            . ' AND isactive = 1';

        $row = db_fetch_row(db_query($sql));
        $count = $row ? (int) $row[0] : 0;

        return max(1, $count); // At least 1 to avoid division by zero
    }

    /**
     * Get total open tickets in a department.
     */
    static function getOpenTicketCount($deptId) {
        $sql = 'SELECT COUNT(*) FROM ' . TICKET_TABLE . ' t'
            . ' JOIN ' . TICKET_STATUS_TABLE . ' s ON (s.id = t.status_id)'
            . ' WHERE s.state = "open"'
            . ' AND t.dept_id = ' . db_input($deptId);

        $row = db_fetch_row(db_query($sql));
        return $row ? (int) $row[0] : 0;
    }

    /**
     * Estimate the wait time for a ticket in business hours.
     *
     * Algorithm:
     *   wait_hours = (tickets_ahead * avg_service_time) / num_agents
     *
     * Returns an associative array with estimation details.
     */
    static function getEstimate($ticket) {
        if (!$ticket || !$ticket->isOpen())
            return null;

        $deptId = $ticket->getDeptId();
        $topicId = $ticket->getTopicId();

        $position = self::getQueuePosition($ticket);
        $avgService = self::getAvgServiceTime($deptId, $topicId);
        $agents = self::getActiveAgentCount($deptId);
        $openCount = self::getOpenTicketCount($deptId);

        // Parallel processing: multiple agents work simultaneously
        $waitHours = ($position * $avgService) / $agents;

        // Estimate the start datetime (clock hours — not business hours)
        $estStart = new DateTime('now');
        $minutes = (int) round($waitHours * 60);
        if ($minutes > 0)
            $estStart->add(new DateInterval('PT' . $minutes . 'M'));

        return array(
            'position'          => $position + 1, // 1-based for display
            'tickets_ahead'     => $position,
            'open_in_dept'      => $openCount,
            'avg_service_hours' => $avgService,
            'agents'            => $agents,
            'est_wait_hours'    => round($waitHours, 1),
            'est_start'         => $estStart->format('Y-m-d H:i:s'),
            'est_start_display' => self::formatWait($waitHours),
        );
    }

    /**
     * Get a compact summary for a department (for staff dashboard).
     */
    static function getDeptSummary($deptId) {
        $agents = self::getActiveAgentCount($deptId);
        $openCount = self::getOpenTicketCount($deptId);
        $avgService = self::getAvgServiceTime($deptId);

        // Average wait for a new ticket entering the queue
        $avgWait = ($openCount * $avgService) / $agents;

        return array(
            'open_tickets'      => $openCount,
            'agents'            => $agents,
            'tickets_per_agent' => round($openCount / $agents, 1),
            'avg_service_hours' => $avgService,
            'est_new_wait'      => round($avgWait, 1),
            'est_wait_display'  => self::formatWait($avgWait),
        );
    }

    /**
     * Get workload summaries for all active departments.
     */
    static function getAllDeptSummaries() {
        $sql = 'SELECT id, name FROM ' . DEPT_TABLE
            . ' WHERE flags & 1' // FLAG_ACTIVE
            . ' ORDER BY name';

        $res = db_query($sql);
        $summaries = array();
        while ($row = db_fetch_row($res)) {
            $summary = self::getDeptSummary($row[0]);
            $summary['dept_id'] = $row[0];
            $summary['dept_name'] = $row[1];
            $summaries[] = $summary;
        }
        return $summaries;
    }

    /**
     * Format wait hours into a human-readable string.
     */
    static function formatWait($hours) {
        if ($hours < 0.5)
            return __('Less than 30 minutes');

        if ($hours < 1)
            return __('About 1 hour');

        if ($hours < 24) {
            $h = (int) round($hours);
            return sprintf(_N('About %d hour', 'About %d hours', $h), $h);
        }

        $days = $hours / 8; // Business days (8-hour workday)
        if ($days < 1.5)
            return __('About 1 business day');

        $d = (int) round($days);
        return sprintf(_N('About %d business day', 'About %d business days', $d), $d);
    }
}
