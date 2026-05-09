<?php
declare(strict_types=1);

namespace Phpdup\Pipeline;

use Symfony\Component\Console\Output\OutputInterface;

interface StageInterface
{
    public function name(): Stage;

    public function run(PipelineState $state, OutputInterface $output): void;
}
