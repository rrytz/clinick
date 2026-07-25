<?php
/**
 * AdminTools.php — Deterministic Tool Implementations for Admin AI Assistant
 */

class AdminTools
{
    private SQLite3 $db;

    public function __construct(SQLite3 $db)
    {
        $this->db = $db;
    }

    public static function getDeclarations(): array
    {
        return [
            [
                'name'        => 'getDailyStats',
                'description' => 'Returns operational statistics for a specific day including scheduled appointments, pending approvals, high-risk flags, fully-booked doctors, and no-show rate.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'date' => ['type' => 'STRING', 'description' => 'Target date in YYYY-MM-DD format. Defaults to today.'],
                    ],
                ],
            ],
            [
                'name'        => 'getWeeklyStats',
                'description' => 'Returns weekly appointment volume, completion rates, peak consultation times, and doctor performance.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => (object)[],
                ],
            ],
            [
                'name'        => 'getMonthlyReport',
                'description' => 'Generates executive monthly operational summary for a target year and month.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'year'  => ['type' => 'INTEGER', 'description' => '4-digit year (e.g. 2026).'],
                        'month' => ['type' => 'INTEGER', 'description' => 'Month number (1-12).'],
                    ],
                ],
            ],
            [
                'name'        => 'getDoctorWorkload',
                'description' => 'Fetches distribution of scheduled patients across doctors to detect workload bottlenecks.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'date_range' => ['type' => 'STRING', 'description' => 'Range filter: today, week, month.'],
                    ],
                ],
            ],
            [
                'name'        => 'getNoShowRate',
                'description' => 'Calculates missed appointment percentage and identifies recurring no-show trends.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'period' => ['type' => 'STRING', 'description' => 'Time period: 7days, 30days, 90days.'],
                    ],
                ],
            ],
            [
                'name'        => 'getPendingApprovals',
                'description' => 'Returns user registration accounts or appointments currently awaiting administrative approval.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => (object)[],
                ],
            ],
            [
                'name'        => 'getHighRiskPatients',
                'description' => 'Lists high-risk patients flagged for urgent clinical review or repeated missed consultations.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => (object)[],
                ],
            ],
            [
                'name'        => 'generateAnalyticsReport',
                'description' => 'Compiles structured executive analytical report on clinic performance and strategic recommendations.',
                'parameters'  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'report_type' => ['type' => 'STRING', 'enum' => ['operations', 'no_shows', 'doctor_capacity', 'comprehensive'], 'description' => 'Type of analytics report.'],
                    ],
                    'required'   => ['report_type'],
                ],
            ],
        ];
    }

    public function getDailyStats(array $args, int $userId): array
    {
        $date = $args['date'] ?? date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day', strtotime($date)));

        // Total appointments today
        $stmtApp = $this->db->prepare("SELECT COUNT(*) as total FROM appointments WHERE appointment_date = :date");
        $stmtApp->bindValue(':date', $date, SQLITE3_TEXT);
        $resApp = $stmtApp->execute();
        $totalApps = $resApp ? $resApp->fetchArray(SQLITE3_ASSOC)['total'] : 0;

        // Pending user approvals
        $stmtPend = $this->db->query("SELECT COUNT(*) as total FROM users WHERE status = 'Pending'");
        $pendingUsers = $stmtPend ? $stmtPend->fetchArray(SQLITE3_ASSOC)['total'] : 0;

        // High risk patients count
        $stmtRisk = $this->db->query("
            SELECT COUNT(DISTINCT patient_id) as total FROM appointments 
            WHERE status = 'No-Show' OR reason LIKE '%urgent%' OR reason LIKE '%severe%'
        ");
        $highRiskCount = $stmtRisk ? $stmtRisk->fetchArray(SQLITE3_ASSOC)['total'] : 0;

        // Doctor fully booked check
        $stmtDoc = $this->db->query("
            SELECT u.name, COUNT(a.id) as app_count 
            FROM users u 
            JOIN appointments a ON u.id = a.doctor_id 
            WHERE a.appointment_date = '$date' AND a.status != 'Cancelled'
            GROUP BY u.id HAVING app_count >= 5
        ");
        $fullyBookedDocs = [];
        while ($row = $stmtDoc->fetchArray(SQLITE3_ASSOC)) {
            $fullyBookedDocs[] = $row['name'];
        }

        // Yesterday no-show rate
        $stmtYestTotal = $this->db->prepare("SELECT COUNT(*) as total FROM appointments WHERE appointment_date = :date");
        $stmtYestTotal->bindValue(':date', $yesterday, SQLITE3_TEXT);
        $yestTotal = ($r = $stmtYestTotal->execute()) ? $r->fetchArray(SQLITE3_ASSOC)['total'] : 0;

        $stmtYestNoShow = $this->db->prepare("SELECT COUNT(*) as total FROM appointments WHERE appointment_date = :date AND status = 'No-Show'");
        $stmtYestNoShow->bindValue(':date', $yesterday, SQLITE3_TEXT);
        $yestNoShow = ($r2 = $stmtYestNoShow->execute()) ? $r2->fetchArray(SQLITE3_ASSOC)['total'] : 0;

        $noShowRate = ($yestTotal > 0) ? round(($yestNoShow / $yestTotal) * 100, 1) : 0;

        return [
            'date'                       => $date,
            'scheduled_appointments'     => (int)$totalApps,
            'pending_approvals'          => (int)$pendingUsers,
            'high_risk_patients_review'  => (int)$highRiskCount,
            'fully_booked_doctors'       => $fullyBookedDocs,
            'yesterday_no_show_rate_pct' => $noShowRate,
        ];
    }

    public function getWeeklyStats(array $args, int $userId): array
    {
        $startDate = date('Y-m-d', strtotime('-7 days'));
        $endDate   = date('Y-m-d');

        $stmt = $this->db->prepare("
            SELECT status, COUNT(*) as count 
            FROM appointments 
            WHERE appointment_date BETWEEN :start_date AND :end_date 
            GROUP BY status
        ");
        $stmt->bindValue(':start_date', $startDate, SQLITE3_TEXT);
        $stmt->bindValue(':end_date', $endDate, SQLITE3_TEXT);
        $res = $stmt->execute();

        $byStatus = [];
        $total = 0;
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $byStatus[$row['status']] = (int)$row['count'];
            $total += (int)$row['count'];
        }

        return [
            'period'             => "$startDate to $endDate",
            'total_appointments' => $total,
            'status_breakdown'   => $byStatus,
        ];
    }

    public function getMonthlyReport(array $args, int $userId): array
    {
        $year  = (int)($args['year'] ?? date('Y'));
        $month = sprintf('%02d', (int)($args['month'] ?? date('m')));
        $prefix = "$year-$month-";

        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total_apps,
                   SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
                   SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled,
                   SUM(CASE WHEN status = 'No-Show' THEN 1 ELSE 0 END) as no_shows
            FROM appointments
            WHERE appointment_date LIKE :prefix
        ");
        $stmt->bindValue(':prefix', "$prefix%", SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : [];

        return [
            'year_month'        => "$year-$month",
            'total_volume'      => (int)($row['total_apps'] ?? 0),
            'completed'         => (int)($row['completed'] ?? 0),
            'cancelled'         => (int)($row['cancelled'] ?? 0),
            'no_shows'          => (int)($row['no_shows'] ?? 0),
            'completion_rate'   => ($row['total_apps'] ?? 0) > 0 ? round(($row['completed'] / $row['total_apps']) * 100, 1) . '%' : '0%',
        ];
    }

    public function getDoctorWorkload(array $args, int $userId): array
    {
        $stmt = $this->db->query("
            SELECT u.id as doctor_id, u.name as doctor_name, COUNT(a.id) as assigned_appointments
            FROM users u
            LEFT JOIN appointments a ON u.id = a.doctor_id AND a.status != 'Cancelled'
            WHERE u.role = 'Doctor'
            GROUP BY u.id
            ORDER BY assigned_appointments DESC
        ");

        $workload = [];
        while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
            $workload[] = [
                'doctor_id'             => $row['doctor_id'],
                'doctor_name'           => $row['doctor_name'],
                'assigned_appointments' => (int)$row['assigned_appointments'],
            ];
        }

        return ['workload_distribution' => $workload];
    }

    public function getNoShowRate(array $args, int $userId): array
    {
        $days = match ($args['period'] ?? '7days') {
            '30days' => 30,
            '90days' => 90,
            default  => 7,
        };

        $startDate = date('Y-m-d', strtotime("-$days days"));

        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN status = 'No-Show' THEN 1 ELSE 0 END) as no_shows
            FROM appointments
            WHERE appointment_date >= :start_date
        ");
        $stmt->bindValue(':start_date', $startDate, SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : ['total' => 0, 'no_shows' => 0];

        $total   = (int)($row['total'] ?? 0);
        $noShows = (int)($row['no_shows'] ?? 0);
        $rate    = $total > 0 ? round(($noShows / $total) * 100, 1) : 0;

        return [
            'period_days'     => $days,
            'total_scheduled' => $total,
            'no_show_count'   => $noShows,
            'no_show_rate'    => "$rate%",
        ];
    }

    public function getPendingApprovals(array $args, int $userId): array
    {
        $stmt = $this->db->query("
            SELECT id, name, email, role, created_at 
            FROM users 
            WHERE status = 'Pending'
            ORDER BY created_at ASC
        ");

        $pending = [];
        while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
            $pending[] = $row;
        }

        return [
            'pending_count' => count($pending),
            'pending_users' => $pending,
        ];
    }

    public function getHighRiskPatients(array $args, int $userId): array
    {
        $stmt = $this->db->query("
            SELECT u.id as patient_id, u.name, u.email, 
                   COUNT(a.id) as total_bookings,
                   SUM(CASE WHEN a.status = 'No-Show' THEN 1 ELSE 0 END) as no_shows
            FROM users u
            JOIN appointments a ON u.id = a.patient_id
            WHERE u.role = 'Patient'
            GROUP BY u.id
            HAVING no_shows >= 2 OR total_bookings > 5
            ORDER BY no_shows DESC
        ");

        $highRisk = [];
        while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
            $highRisk[] = $row;
        }

        return [
            'high_risk_count'    => count($highRisk),
            'high_risk_patients' => $highRisk,
        ];
    }

    public function generateAnalyticsReport(array $args, int $userId): array
    {
        $type = $args['report_type'] ?? 'comprehensive';

        $dailyStats   = $this->getDailyStats([], $userId);
        $noShowData   = $this->getNoShowRate(['period' => '30days'], $userId);
        $workloadData = $this->getDoctorWorkload([], $userId);

        return [
            'report_title'   => 'CLINICK Executive Operations Analytics',
            'report_type'    => $type,
            'generated_at'   => date('Y-m-d H:i:s'),
            'summary'        => $dailyStats,
            'no_show_trends' => $noShowData,
            'capacity'       => $workloadData,
        ];
    }
}
