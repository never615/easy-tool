<?php

namespace Mallto\Tool\Controller\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Mallto\Tool\Domain\LogViewer\RootOnlyLaravelLogViewer;

class SystemLogController extends Controller
{
    /**
     * @var Request
     */
    protected $request;

    /**
     * @var RootOnlyLaravelLogViewer
     */
    private $logViewer;

    /**
     * @var string
     */
    protected $viewLog = 'laravel-log-viewer::log';

    public function __construct()
    {
        $this->logViewer = new RootOnlyLaravelLogViewer();
        $this->request = app('request');
    }

    /**
     * @return array|mixed
     * @throws \Exception
     */
    public function index()
    {
        if ($this->request->input('l')) {
            $this->logViewer->setFile(Crypt::decrypt($this->request->input('l')));
        }

        if ($earlyReturn = $this->earlyReturn()) {
            return $earlyReturn;
        }

        $data = [
            'logs' => $this->logViewer->all(),
            'folders' => [],
            'current_folder' => '',
            'folder_files' => [],
            'files' => $this->logViewer->getFiles(true),
            'current_file' => $this->logViewer->getFileName(),
            'standardFormat' => true,
            'structure' => [],
            'storage_path' => $this->logViewer->getStoragePath(),
        ];

        if ($this->request->wantsJson()) {
            return $data;
        }

        if (is_array($data['logs']) && count($data['logs']) > 0) {
            $firstLog = reset($data['logs']);
            if ($firstLog && !$firstLog['context'] && !$firstLog['level']) {
                $data['standardFormat'] = false;
            }
        }

        return app('view')->make($this->viewLog, $data);
    }

    /**
     * @return bool|mixed
     * @throws \Exception
     */
    private function earlyReturn()
    {
        if ($this->request->input('dl')) {
            return $this->download($this->pathFromInput('dl'));
        } elseif ($this->request->has('clean')) {
            app('files')->put($this->pathFromInput('clean'), '');
            return $this->redirect(url()->previous());
        } elseif ($this->request->has('del')) {
            app('files')->delete($this->pathFromInput('del'));
            return $this->redirect($this->request->url());
        } elseif ($this->request->has('delall')) {
            foreach ($this->logViewer->getFiles(true) as $file) {
                app('files')->delete($this->logViewer->pathToLogFile($file));
            }

            return $this->redirect($this->request->url());
        }

        return false;
    }

    /**
     * @param string $inputString
     *
     * @return string
     * @throws \Exception
     */
    private function pathFromInput($inputString)
    {
        return $this->logViewer->pathToLogFile(Crypt::decrypt($this->request->input($inputString)));
    }

    /**
     * @param $to
     *
     * @return mixed
     */
    private function redirect($to)
    {
        if (function_exists('redirect')) {
            return redirect($to);
        }

        return app('redirect')->to($to);
    }

    /**
     * @param string $data
     *
     * @return mixed
     */
    private function download($data)
    {
        if (function_exists('response')) {
            return response()->download($data);
        }

        return app('\Illuminate\Support\Facades\Response')->download($data);
    }
}
