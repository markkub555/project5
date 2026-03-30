<?php
require_once __DIR__ . '/includes/render_status_page.php';

renderStatusPage([
    'page_title' => 'ตรวจลายนิ้วมือ ศพฐ.',
    'page_file' => 'fingerprint_check.php',
    'status_column' => 'fingerprint_check',
    'note_column' => 'fingerprint_check_note',
    'update_endpoint' => './update/update_fingerprint_check.php',
    'note_options' => ['ขาดสอบ'],
    'required_note_statuses' => ['F'],
    'show_note_statuses' => ['F'],
    'status_text' => ['F' => 'ไม่ไปตรวจ'],
]);
