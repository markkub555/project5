<?php
require_once __DIR__ . '/includes/render_status_page.php';

renderStatusPage([
    'page_title' => 'ว่ายน้ำ',
    'page_file' => 'swim.php',
    'status_column' => 'swim_test',
    'note_column' => 'swim_test_note',
    'update_endpoint' => './update/update_swim_test.php',
    'note_options' => ['ขาดสอบ', 'ตกว่ายน้ำ', 'ทุจริต'],
    'required_note_statuses' => ['F'],
    'show_note_statuses' => ['F'],
]);
