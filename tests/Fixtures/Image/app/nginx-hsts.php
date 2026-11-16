<?php

declare(strict_types=1);

// A stand-in for docker/teme/nginx-hsts.php, which needs the application's packages.
echo "map \"\$upstream_addr|\$http_x_forwarded_proto\" \$teme_hsts {\n    default \"\";\n}\n";
