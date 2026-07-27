<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

logout_tenant();
header('Location: login.php');
exit;
