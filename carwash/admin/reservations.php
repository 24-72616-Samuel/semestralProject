<?php
require __DIR__ . '/../includes/bootstrap.php';
require_role(STAFF_ROLES);
require __DIR__ . '/../includes/reservations_panel_post.php';   // Accept / Change bay / Start / Complete / Cancel
$title = 'Reservations';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/reservations_panel_view.php';   // list + actions
require __DIR__ . '/../includes/footer.php';
