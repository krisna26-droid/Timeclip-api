<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TestRenderClip extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-render-clip';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $clips = \App\Models\Clip::where('status', 'rendering')->get();
            foreach ($clips as $clip) {
                $this->info("Memproses Klip ID: " . $clip->id);
                \App\Jobs\ProcessVideoClipJob::dispatch($clip);
            }
    }
}
