<?php
require_once __DIR__ . '/includes/render_status_page.php';

renderStatusPage([
    'page_title' => 'ตรวจ LAB',
    'page_file' => 'lab_check.php',
    'status_column' => 'lab_check',
    'note_column' => 'lab_check_note',
    'update_endpoint' => './update/update_lab_check.php',
    'note_options' => ['ไม่มาตรวจ lab'],
    'required_note_statuses' => ['F'],
    'show_note_statuses' => ['F'],
]);
