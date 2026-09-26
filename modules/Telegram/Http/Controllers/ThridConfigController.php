<?php
declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Illuminate\Http\Request;
use Modules\Telegram\Models\ThirdApiConfig;


class ThridConfigController extends Controller
{
    public function __construct(
        protected readonly ThirdApiConfig $model
    ){}

    /**
     * @return mixed
     */
    public function index(): mixed
    {
        return $this->model->getList();
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request)
    {
        return $this->model->storeBy($request->all());
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        return $this->model->firstBy($id);
    }

    /**
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function update($id, Request $request)
    {
        //如果参数是******************则不更新该字段
        $data = $request->all();
        if (isset($data['token']) && $data['token'] === '******************') {
            unset($data['token']);
        }
        if (isset($data['secrept_key']) && $data['secrept_key'] === '******************') {
            unset($data['secrept_key']);
        }
        $request->replace($data);
        return $this->model->updateBy($id, $request->all());
    }

    /**
     * @param $id
     * @return mixed
     */
    public function destroy($id)
    {
        return $this->model->deleteBy($id);
    }
}
