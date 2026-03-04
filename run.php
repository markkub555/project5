<?php
require_once __DIR__ . '/includes/render_status_page.php';

renderStatusPage([
    'page_title' => 'วิ่ง',
    'page_file' => 'run.php',
    'status_column' => 'run_test',
    'note_column' => 'run_test_note',
    'update_endpoint' => './update/update_run.php',
    'note_options' => ['ไม่มาสอบวิ่ง', 'ตกวิ่ง', 'ทุจริต'],
    'required_note_statuses' => ['F'],
    'show_note_statuses' => ['F'],
]);
