<?php
require_once __DIR__ . '/includes/render_status_page.php';

renderStatusPage([
    'page_title' => 'ตรวจร่างกาย รพ.ตร.',
    'page_file' => 'hospital_check.php',
    'status_column' => 'hospital_check',
    'note_column' => 'hospital_check_note',
    'update_endpoint' => './update/update_hospital_check.php',
    'note_options' => ['ไม่มาตรวจ', 'รอยสัก', 'สายตาสั้น', 'ตาบอดสี', 'ส่วนสูง', 'น้ำหนัก'],
    'required_note_statuses' => ['F'],
    'show_note_statuses' => ['F'],
]);
