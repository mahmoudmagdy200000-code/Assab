<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // Skip views and procedures for SQLite (not supported)
        if ($driver === 'sqlite') {
            return;
        }

        if (!Schema::hasTable('cashiers') ||
        !Schema::hasTable('branches') ||
        !Schema::hasTable('branch_managers') ||
        !Schema::hasTable('cashier_shifts')) {
        return; // skip if tables not ready
    }
        // ================================
        // VIEWS
        // ================================
        // Drop view if exists (SQLite-compatible approach)
        DB::statement("DROP VIEW IF EXISTS vw_cashier_summary;");
        
        DB::statement("
            CREATE VIEW vw_cashier_summary AS
            SELECT
                c.id,
                c.name,
                c.email,
                c.phone,
                c.image,
                c.status,
                b.name as branch_name,
                b.id as branch_id,
                COUNT(DISTINCT cs.shift_id) as total_shifts_assigned,
                bm.name as created_by_name,
                c.created_at,
                c.activated_at
            FROM cashiers c
            JOIN branches b ON c.branch_id = b.id
            JOIN branch_managers bm ON c.created_by = bm.id
            LEFT JOIN cashier_shifts cs ON c.id = cs.cashier_id
            GROUP BY c.id, c.name, c.email, c.phone, c.image, c.status,
                    b.name, b.id, bm.name, c.created_at, c.activated_at;
        ");

        DB::statement("DROP VIEW IF EXISTS vw_pending_shifts;");
        
        // Use database-specific date function
        $dateFunction = $driver === 'mysql' ? 'CURDATE()' : 'DATE("now")';
        
        DB::statement("
            CREATE VIEW vw_pending_shifts AS
            SELECT
                cs.id as shift_assignment_id,
                cs.shift_date,
                cs.status,
                s.name as shift_name,
                s.start_time,
                s.end_time,
                cs.opening_balance,
                c.id as cashier_id,
                c.name as cashier_name,
                nc.id as next_cashier_id,
                nc.name as next_cashier_name,
                b.name as branch_name,
                CASE
                    WHEN cs.original_cashier_id IS NOT NULL THEN 'reassigned'
                    ELSE 'not_started'
                END as shift_status
            FROM cashier_shifts cs
            JOIN shifts s ON cs.shift_id = s.id
            JOIN cashiers c ON cs.cashier_id = c.id
            JOIN branches b ON c.branch_id = b.id
            LEFT JOIN cashiers nc ON cs.next_cashier_id = nc.id
            WHERE cs.status = 'not_started'
            AND cs.shift_date >= {$dateFunction}
            ORDER BY cs.shift_date ASC, s.start_time ASC;
        ");

        // ================================
        // STORED PROCEDURES (MySQL/MariaDB only)
        // ================================
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared("
                DROP PROCEDURE IF EXISTS GetNextCashier;
            ");

            DB::unprepared("
                CREATE PROCEDURE GetNextCashier(
                    IN p_current_shift_id INT,
                    IN p_shift_date DATE,
                    IN p_branch_id INT
                )
                BEGIN
                    SELECT
                        cs.cashier_id,
                        c.name as cashier_name,
                        s.name as shift_name,
                        s.start_time,
                        s.end_time
                    FROM cashier_shifts cs
                    JOIN cashiers c ON cs.cashier_id = c.id
                    JOIN shifts s ON cs.shift_id = s.id
                    WHERE cs.shift_date = p_shift_date
                    AND s.branch_id = p_branch_id
                    AND s.start_time = (
                        SELECT end_time
                        FROM shifts
                        WHERE id = p_current_shift_id
                    )
                    AND cs.status = 'not_started'
                    LIMIT 1;
                END;
            ");

            DB::unprepared("
                DROP PROCEDURE IF EXISTS CheckShiftAvailability;
            ");

            DB::unprepared("
                CREATE PROCEDURE CheckShiftAvailability(
                    IN p_shift_id INT,
                    IN p_shift_date DATE,
                    IN p_cashier_id INT
                )
                BEGIN
                    SELECT
                        CASE
                            WHEN COUNT(*) > 0 THEN 'occupied'
                            ELSE 'available'
                        END as availability,
                        c.name as occupied_by
                    FROM cashier_shifts cs
                    LEFT JOIN cashiers c ON cs.cashier_id = c.id
                    WHERE cs.shift_id = p_shift_id
                    AND cs.shift_date = p_shift_date
                    AND cs.cashier_id != p_cashier_id
                    AND cs.status != 'reassigned'
                    GROUP BY c.name;
                END;
            ");
        }
    }

    public function down(): void
    {
        DB::statement("DROP VIEW IF EXISTS vw_cashier_summary;");
        DB::statement("DROP VIEW IF EXISTS vw_pending_shifts;");
        DB::unprepared("DROP PROCEDURE IF EXISTS GetNextCashier;");
        DB::unprepared("DROP PROCEDURE IF EXISTS CheckShiftAvailability;");
    }
};
