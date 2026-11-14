<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCbtExamNamesTable extends Migration
{
    public function up()
    {
        helper('migration');

        // 1. Buat tabel cbt_exam_names jika belum ada
        if (!table_exists('cbt_exam_names')) {
            $this->forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 100,
                    'null'       => false,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('cbt_exam_names', true);
            log_message('info', '[Migration] Table cbt_exam_names created successfully.');
        }

        // 2. Tambah kolom exam_name_id pada cbt_test_status jika belum ada
        if (table_exists('cbt_test_status') && !column_exists('cbt_test_status', 'exam_name_id')) {
            safe_add_column($this->forge, 'cbt_test_status', [
                'exam_name_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                    'null'       => true,
                    'after'      => 'bank_id',
                ],
            ]);
            log_message('info', '[Migration] Column exam_name_id added to cbt_test_status.');
        }
    }

    public function down()
    {
        helper('migration');
        if (table_exists('cbt_exam_names')) {
            $this->forge->dropTable('cbt_exam_names', true);
        }
    }
}
