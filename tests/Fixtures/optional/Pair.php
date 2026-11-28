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
            // E3 6.2 span pad 01 - comments never enter the canonical AST
            // E3 6.2 span pad 02 - comments never enter the canonical AST
            // E3 6.2 span pad 03 - comments never enter the canonical AST
            // E3 6.2 span pad 04 - comments never enter the canonical AST
            // E3 6.2 span pad 05 - comments never enter the canonical AST
            // E3 6.2 span pad 06 - comments never enter the canonical AST
            // E3 6.2 span pad 07 - comments never enter the canonical AST
            // E3 6.2 span pad 08 - comments never enter the canonical AST
            // E3 6.2 span pad 09 - comments never enter the canonical AST
            // E3 6.2 span pad 10 - comments never enter the canonical AST
            // E3 6.2 span pad 11 - comments never enter the canonical AST
            // E3 6.2 span pad 12 - comments never enter the canonical AST
            // E3 6.2 span pad 13 - comments never enter the canonical AST
            // E3 6.2 span pad 14 - comments never enter the canonical AST
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
            // E3 6.2 span pad 01 - comments never enter the canonical AST
            // E3 6.2 span pad 02 - comments never enter the canonical AST
            // E3 6.2 span pad 03 - comments never enter the canonical AST
            // E3 6.2 span pad 04 - comments never enter the canonical AST
            // E3 6.2 span pad 05 - comments never enter the canonical AST
            // E3 6.2 span pad 06 - comments never enter the canonical AST
            // E3 6.2 span pad 07 - comments never enter the canonical AST
            // E3 6.2 span pad 08 - comments never enter the canonical AST
            // E3 6.2 span pad 09 - comments never enter the canonical AST
            // E3 6.2 span pad 10 - comments never enter the canonical AST
            // E3 6.2 span pad 11 - comments never enter the canonical AST
            // E3 6.2 span pad 12 - comments never enter the canonical AST
            // E3 6.2 span pad 13 - comments never enter the canonical AST
            // E3 6.2 span pad 14 - comments never enter the canonical AST
        } else {
            return false;
        }
    }
}
