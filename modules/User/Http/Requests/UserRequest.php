<?php

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    /**
     * rules
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            // username 在库里有唯一索引但原来没校验，撞了会直接抛 SQL 异常变 500。
            'username' => [
                'required',
                Rule::unique('users')->where(function ($query) {
                    return $query->when($this->get('id'), function ($query) {
                        $query->where('id', '<>', $this->get('id'));
                    })->where('deleted_at', 0);
                })
            ],
            'email' => [
              'required',
               Rule::unique('users')->where(function ($query) {
                   return $query->when($this->get('id'), function ($query){
                       $query->where('id', '<>', $this->get('id'));
                   })->where('deleted_at', 0);
               })
            ],
        ];
    }


    /**
     * messages
     *
     * @return string[]
     */
    public function messages(): array
    {
        return [
            'username.required' => '昵称必须填写',
            'username.unique' => '昵称已存在',
            'email.required' => '邮箱必须填写',
            'email.unique' => '邮箱已存在',
        ];
    }
}
