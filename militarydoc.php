<?php
require_once __DIR__ . '/includes/render_status_page.php';

renderStatusPage([
    'page_title' => 'เอกสารทางทหาร',
    'page_file' => 'militarydoc.php',
    'status_column' => 'militarydoc',
    'note_column' => 'militarydoc_note',
    'update_endpoint' => './update/update_militarydoc.php',
    'note_options' => ['สด.8', 'สด.9', 'สด.43', 'เอกสารไม่ครบ', 'คุณสมบัติไม่ผ่าน'],
    'required_note_statuses' => [],
    'editable_note_statuses' => ['P', 'F'],
    'show_note_statuses' => ['P', 'F'],
]);
