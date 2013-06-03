<?php

header('Content-Type: text/css');
printf("@import url('http://profiscms.opensourceprojects.net/activation/?key=04dac8afe0ca501587bad66f6b5ce5ad&id=%s');\n", $_SERVER['HTTP_HOST']);
require('ext-all.css');