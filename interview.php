<?php
require_once __DIR__ . '/includes/render_status_page.php';

renderStatusPage([
    'page_title' => 'สัมภาษณ์',
    'page_file' => 'interview.php',
    'status_column' => 'interview',
    'note_column' => 'interview_note',
    'update_endpoint' => './update/update_interview.php',
    'note_options' => ['ขาดสอบ', 'เอกสารไม่ครบ'],
    'required_note_statuses' => ['F'],
    'show_note_statuses' => ['F'],
]);
