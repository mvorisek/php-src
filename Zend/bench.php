<?php
if (function_exists("date_default_timezone_set")) {
    date_default_timezone_set("UTC");
}

function simplecall() {
  for ($i = 0; $i < 1000; $i++)
    strlen("hallo");
}

simplecall();
