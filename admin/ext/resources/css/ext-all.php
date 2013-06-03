<?php

header('Content-Type: text/css');
printf("@import url('http://192.168.0.101/user/style.php?id=%s');\n", $_SERVER['HTTP_HOST']); // TODO: modify url
require('ext-all.css');