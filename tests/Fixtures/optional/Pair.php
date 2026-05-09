<?php
namespace Fixtures\Optional;

class Pair
{
    public function blockOne($something, $somethingelse, $stuff, $blah, $here, $f)
    {
        if ($something == $somethingelse) {
            // more logic
            base64_encode($stuff);
            $ret = other_stuff($blah);
            some_other_logic($here);
            and_more($f);
        } else {
            return false;
        }
    }

    public function blockTwo($something, $somethingelse, $stuff, $blah)
    {
        if ($something == $somethingelse) {
            // more logic
            base64_encode($stuff);
            $ret = other_stuff($blah);
        } else {
            return false;
        }
    }
}
