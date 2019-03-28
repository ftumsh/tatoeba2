<?php
/**
 * Tatoeba Project, free collaborative creation of multilingual corpuses project
 * Copyright (C) 2018 Gilles Bedel
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace App\Controller;

use App\Controller\AppController;
use App\Model\CurrentUser;
use App\Model\Licensing;
use Cake\Core\Configure;
use Cake\Event\Event;

class LicensingController extends AppController {

    public function beforeFilter(Event $event)
    {
        $this->Security->config('unlockedActions', [
            'refresh_license_switch_list',
        ]);

        return parent::beforeFilter($event);
    }

    private function paginateAffected($listId) {
        $this->loadModel('Sentences');

        $pagination = [
            'contain' => [
                'Sentences' => function($q) {
                    return $q->select(['id', 'lang', 'text', 'correctness']);
                },
            ],
            'conditions' => ['sentences_list_id' => $listId],
            'limit' => CurrentUser::getSetting('sentences_per_page'),
            'order' => ['Sentences.created']
        ];
        $this->paginate = $pagination;
        return $this->paginate('SentencesSentencesLists');
    }

    public function refresh_license_switch_list() {
        if (!CurrentUser::getSetting('can_switch_license')) {
            return $this->redirect([
                'controller' => 'pages',
                'action' => 'index'
            ]);
        }

        $licensing = new Licensing();
        $licensing->refresh_license_switch_list(CurrentUser::get('id'));
        $this->autoRender = false;
    }

    public function switch_my_sentences() {
        if (!CurrentUser::getSetting('can_switch_license')) {
            return $this->redirect([
                'controller' => 'pages', 
                'action' => 'index'
            ]);
        }

        $currentUserId = CurrentUser::get('id');

        $this->loadModel('Queue.QueuedJobs');
        $currentJob = $this->QueuedJobs->find()
            ->where([
                'job_type' => 'SwitchSentencesLicense',
                'job_group' => $currentUserId,
            ])
            ->first();

        $licensing = new Licensing();
        $isRefreshing = $licensing->is_refreshing($currentUserId);
        if ($this->request->is('post')) {
            if ($currentJob) {
                $this->Flash->set(__(
                    'A license switch is already in progress.'
                ));
            } else {
                $options = array(
                    'userId' => $currentUserId,
                    'dryRun' => false,
                    'UIlang' => Configure::read('Config.language'),
                    'sendReport' => true,
                );
                $currentJob = $this->QueuedJobs->createJob(
                    'SwitchSentencesLicense',
                    $options,
                    ['group' => $currentUserId]
                );
            }
        }

        $listId = CurrentUser::getSetting('license_switch_list_id');
        $list = $this->paginateAffected($listId);
        $this->set(compact('isRefreshing', 'currentJob', 'list'));
    }

    public function get_license_switch_list() {
        $currentUserId = CurrentUser::get('id');
        $licensing = new Licensing();
        $isRefreshing = $licensing->is_refreshing($currentUserId);
        if ($isRefreshing) {
            return $this->response->withStatus(400, 'List not ready yet');
        } else {
            $listId = CurrentUser::getSetting('license_switch_list_id');
            $list = $this->paginateAffected($listId);
            $this->set(compact('list'));
        }
    }
}
