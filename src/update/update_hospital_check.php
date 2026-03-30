<?php
require_once '../config/db.php';
require_once __DIR__ . '/status_update_helper.php';

handleStatusUpdate($conn, 'hospital_check', 'hospital_check_note', ['F']);
