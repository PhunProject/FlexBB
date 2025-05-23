<?php
/**
 * This file is part of the ForkBB <https://github.com/forkbb>.
 *
 * @copyright (c) Visman <mio.visman@yandex.ru, https://github.com/MioVisman>
 * @license   The MIT License (MIT)
 */

declare(strict_types=1);

namespace ForkBB\Models\Pages\Admin;

use ForkBB\Models\Page;
use ForkBB\Models\Pages\Admin;
use function \ForkBB\__;

class Censoring extends Admin
{
    /**
     * Просмотр, редактирвоание и добавление запрещенных слов
     */
    public function edit(array $args, string $method): Page
    {
        $this->c->Lang->load('validator');
        $this->c->Lang->load('admin_censoring');

        if ('POST' === $method) {
            $v = $this->c->Validator->reset()
                ->addRules([
                    'token'               => 'token:AdminCensoring',
                    'b_censoring'         => 'required|integer|in:0,1',
                    'i_censoring_count'   => 'required|integer|min:0|max:999',
                    'form'                => 'required|array',
                    'form.*.search_for'   => 'exist|string:trim|max:60',
                    'form.*.replace_with' => 'exist|string:trim|max:60',
                ])->addAliases([
                ])->addArguments([
                ])->addMessages([
                ]);

            if ($v->validation($_POST)) {
                $this->c->config->b_censoring       = $v->b_censoring;
                $this->c->config->i_censoring_count = $v->i_censoring_count;

                $this->c->config->save();
                $this->c->censorship->save($v->form);

                return $this->c->Redirect->page('AdminCensoring')->message('Data updated redirect', FORK_MESS_SUCC);
            }

            $this->fIswev  = $v->getErrors();
        }

        $this->nameTpl   = 'admin/form';
        $this->aIndex    = 'censoring';
        $this->form      = $this->formEdit();
        $this->classForm = ['editcensorship'];
        $this->titleForm = 'Censoring';

        return $this;
    }

    /**
     * Подготавливает массив данных для формы
     */
    protected function formEdit(): array
    {
        $form = [
            'action' => $this->c->Router->link('AdminCensoring'),
            'hidden' => [
                'token' => $this->c->Csrf->create('AdminCensoring'),
            ],
            'sets'   => [
                'onoff' => [
                    'fields' => [
                        'b_censoring' => [
                            'type'    => 'radio',
                            'value'   => $this->c->config->b_censoring,
                            'values'  => [1 => __('Yes'), 0 => __('No')],
                            'caption' => 'Censor words label',
                            'help'    => 'Censor words help',
                        ],
                        'i_censoring_count' => [
                            'type'    => 'number',
                            'min'     => '0',
                            'max'     => '999',
                            'value'   => $this->c->config->i_censoring_count,
                            'caption' => 'Censor words count label',
                            'help'    => 'Censor words count help',
                        ],
                    ],
                ],
                'onoff-info' => [
                    'inform' => [
                        [
                            'message' => 'Censoring info',
                        ],
                    ],
                ],
            ],
            'btns'   => [
                'submit' => [
                    'type'  => 'submit',
                    'value' => __('Save changes'),
                ],
            ],
        ];

        $fieldset = [];

        foreach ($this->c->censorship->load() as $id => $row) {
            $fieldset["form[{$id}][search_for]"] = [
                'class'     => ['censor'],
                'type'      => 'text',
                'maxlength' => '60',
                'value'     => $row['search_for'],
                'caption'   => 'Censored word label',
            ];
            $fieldset["form[{$id}][replace_with]"] = [
                'class'     => ['censor'],
                'type'      => 'text',
                'maxlength' => '60',
                'value'     => $row['replace_with'],
                'caption'   => 'Replacement label',
            ];
        }

        $fieldset["form[0][search_for]"] = [
            'class'     => ['censor'],
            'type'      => 'text',
            'maxlength' => '60',
            'value'     => '',
            'caption'   => 'Censored word label',
        ];
        $fieldset["form[0][replace_with]"] = [
            'class'     => ['censor'],
            'type'      => 'text',
            'maxlength' => '60',
            'value'     => '',
            'caption'   => 'Replacement label',
        ];

        $form['sets']['censtable'] = [
            'class'  => ['censor'],
            'fields' => $fieldset,
        ];

        return $form;
    }
}
