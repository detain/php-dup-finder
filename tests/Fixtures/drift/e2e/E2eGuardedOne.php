<?php

class E2eGuardedOne
{
    public function handle($id)
    {
        if (!is_numeric($id)) {
            reject('invalid id');
            mark_bad($id);
            emit_audit($id);
            bump_metric('guard.reject');
            return;
        }
        $a = run_alpha($id);
        $b = run_beta($a);
        $c = run_gamma($b);
        $d = run_delta($c);
        $e = run_omega($d);
        $f = run_sigma($e);
        $g = run_tau($f);
        $h = run_zeta($g);
        $i = run_eta($h);
        $j = run_theta($i);
        $k = run_iota($j);
        $l = run_kappa($k);
        $m = run_lambda($l);
        $n = run_mu($m);
        $o = run_nu($n);
        return ship($o);
    }
}
