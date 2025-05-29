--TEST--
Typed property must cast when used with &__get()
--FILE--
<?php

class Test {
    public $prop = "42";
    public int $val;

    public function &__get($name) {
        return $this->prop;
    }
}

$test = new Test;
unset($test->val);
var_dump($test);
var_dump($val = &$test->val);
var_dump($test);

$test->prop = "x";
var_dump($test, $val);

?>
--EXPECT--
object(Test)#1 (1) {
  ["prop"]=>
  string(2) "42"
  ["val"]=>
  unset(int)
}
int(42)
object(Test)#1 (1) {
  ["prop"]=>
  &int(42)
  ["val"]=>
  unset(int)
}
object(Test)#1 (1) {
  ["prop"]=>
  &string(1) "x"
  ["val"]=>
  unset(int)
}
string(1) "x"
