<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSignatureToSchoolProfile extends Migration
{
    public function up()
    {
        helper('migration');
        // Add 'signature' column (nullable string) to school_profile table
        $fields = [
            'signature' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
        ];
        safe_add_column($this->forge, 'school_profile', $fields);
    }

    public function down()
    {
        // Remove the 'signature' column
        $this->forge->dropColumn('school_profile', 'signature');
    }
}
?>
