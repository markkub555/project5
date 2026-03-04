<?php
require_once __DIR__ . '/includes/render_status_page.php';

renderStatusPage([
    'page_title' => 'ตรวจประวัติทางคดี',
    'page_file' => 'background_check.php',
    'status_column' => 'background_check',
    'note_column' => 'background_check_note',
    'update_endpoint' => './update/update_background_check.php',
    'required_note_statuses' => ['F'],
    'show_note_statuses' => ['F'],
    'status_text' => ['F' => 'พบประวัติ'],
]);
