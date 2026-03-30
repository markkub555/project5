<?php
require_once __DIR__ . '/includes/render_status_page.php';

renderStatusPage([
    'page_title' => '๓ สถานี',
    'page_file' => '3station.php',
    'status_column' => 'station3_test',
    'note_column' => 'station3_test_note',
    'update_endpoint' => './update/update_3station.php',
    'note_options' => ['ขาดสอบ', 'ตกวิ่ง 50 เมตร', 'ตกวิ่งเก็บของ', 'ตกกระโดดไกล', 'ตก 3 สถานี'],
    'required_note_statuses' => ['F'],
    'show_note_statuses' => ['F'],
]);
