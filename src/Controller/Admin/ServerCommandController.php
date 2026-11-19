<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Mallto\Admin\AdminUtils;
use Mallto\Tool\Domain\ServerCommands\ServerCommandException;
use Mallto\Tool\Domain\ServerCommands\ServerCommandService;

/** HTTP only admits operations and reads their records; CLI work belongs to the dedicated worker. */
final class ServerCommandController extends Controller
{
    public function index(ServerCommandService $service): mixed
    {
        $actor = Admin::user();
        // The service authorizes index access before exposing even the preset catalogue.
        $presets = $service->presets($actor);

        return Admin::content(function (Content $content) use ($actor, $presets): void {
            $content->header('服务器命令');
            $content->description('配置中心 / 运维执行与记录');
            $content->body(view('easy-tool::server_commands.index', [
                'presets' => $presets,
                'enabled' => filter_var(config('server_commands.enabled', false), FILTER_VALIDATE_BOOLEAN),
                'canAny' => AdminUtils::isOwner() || (bool)$actor?->can('server_commands.execute_any'),
                'currentSubject' => $actor?->subject_id,
                'webHostname' => gethostname() ?: 'unknown',
                'webEnvironment' => app()->environment(),
                'csrfToken' => csrf_token(),
                'urls' => [
                    'preview' => route('server_commands.preview'),
                    'submit' => route('server_commands.submit'),
                    'history' => route('server_commands.history'),
                    'show' => route('server_commands.show', ['id' => '__ID__']),
                    'release' => route('server_commands.release', ['id' => '__ID__']),
                ],
            ]));
        });
    }

    public function preview(Request $request, ServerCommandService $service): JsonResponse
    {
        return $this->respond('preview', fn () => $service->preview(Admin::user(), $this->input($request)));
    }

    public function submit(Request $request, ServerCommandService $service): JsonResponse
    {
        return $this->respond('submit', fn () => $service->submit(Admin::user(), $this->input($request)));
    }

    public function history(ServerCommandService $service): JsonResponse
    {
        return $this->respond('history', fn () => $service->history(Admin::user(), 20));
    }

    public function show(string $id, Request $request, ServerCommandService $service): JsonResponse
    {
        return $this->respond('show', fn () => $service->show(Admin::user(), $id, $request->boolean('output', true)));
    }

    public function release(string $id, Request $request, ServerCommandService $service): JsonResponse
    {
        return $this->respond('release', fn () => $service->release(
            Admin::user(), $id, is_string($request->input('confirmation')) ? $request->input('confirmation') : ''
        ));
    }

    private function input(Request $request): array
    {
        return $request->only('preset_id', 'text', 'parameters', 'timeout', 'submission_id', 'confirmation_token');
    }

    /** Never let framework exception rendering or ordinary request logging expose command bodies. */
    private function respond(string $action, \Closure $operation): JsonResponse
    {
        try {
            return response()->json(['data' => $operation()])->header('Cache-Control', 'no-store');
        } catch (AuthorizationException) {
            return $this->error('没有执行此操作或查看该记录的权限。', 403);
        } catch (ModelNotFoundException) {
            return $this->error('执行记录不存在或已清理。', 404);
        } catch (ServerCommandException $error) {
            return $this->error($error->getMessage(), 409);
        } catch (\InvalidArgumentException $error) {
            return $this->error($error->getMessage(), 422);
        } catch (\Throwable $error) {
            Log::error('[ServerCommands] 管理请求未完成', [
                'action' => $action,
                'admin_user_id' => Admin::user()?->id,
                'exception_class' => get_class($error),
            ]);
            return $this->error('操作未完成，请查看执行记录或联系运维核对；不要重复提交命令。', 503);
        }
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status)->header('Cache-Control', 'no-store');
    }
}
