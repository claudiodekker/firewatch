<?php

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Workbench\App\Members;

class AuditMember extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'members:audit {member}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sign one member in and read their account, so the command\'s query carries the member';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $member = Members::find($this->argument('member'));

        if ($member === null) {
            $this->components->error('No such member.');

            return self::FAILURE;
        }

        Auth::setUser($member);

        DB::select('select ? as member', [$member->getAuthIdentifier()]);

        return self::SUCCESS;
    }
}
