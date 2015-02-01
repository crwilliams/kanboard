<?php

namespace Controller;

use Model\Project as ProjectModel;

/**
 * Task controller
 *
 * @package  controller
 * @author   Frederic Guillot
 */
class Task extends Base
{
    /**
     * Public access (display a task)
     *
     * @access public
     */
    public function readonly()
    {
        $project = $this->project->getByToken($this->request->getStringParam('token'));

        // Token verification
        if (! $project) {
            $this->forbidden(true);
        }

        $task = $this->taskFinder->getDetails($this->request->getIntegerParam('task_id'));

        if (! $task) {
            $this->notfound(true);
        }

        $this->response->html($this->template->layout('task/public', array(
            'project' => $project,
            'comments' => $this->comment->getAll($task['id']),
            'subtasks' => $this->subTask->getAll($task['id']),
            'task' => $task,
            'columns_list' => $this->board->getColumnsList($task['project_id']),
            'colors_list' => $this->color->getList(),
            'title' => $task['title'],
            'no_layout' => true,
            'auto_refresh' => true,
            'not_editable' => true,
        )));
    }

    /**
     * Show a task
     *
     * @access public
     */
    public function show()
    {
        $task = $this->getTask();
        $subtasks = $this->subTask->getAll($task['id']);

        $values = array(
            'id' => $task['id'],
            'date_started' => $task['date_started'],
            'time_estimated' => $task['time_estimated'] ?: '',
            'time_spent' => $task['time_spent'] ?: '',
        );

        $this->dateParser->format($values, array('date_started'));

        $this->response->html($this->taskLayout('task/show', array(
            'project' => $this->project->getById($task['project_id']),
            'files' => $this->file->getAll($task['id']),
            'comments' => $this->comment->getAll($task['id']),
            'subtasks' => $subtasks,
            'task' => $task,
            'values' => $values,
            'timesheet' => $this->timeTracking->getTaskTimesheet($task, $subtasks),
            'columns_list' => $this->board->getColumnsList($task['project_id']),
            'colors_list' => $this->color->getList(),
            'date_format' => $this->config->get('application_date_format'),
            'date_formats' => $this->dateParser->getAvailableFormats(),
            'title' => $task['project_name'].' &gt; '.$task['title'],
        )));
    }

    /**
     * Display a form to create a new task
     *
     * @access public
     */
    public function create(array $values = array(), array $errors = array())
    {
        $project = $this->getProject();
        $method = $this->request->isAjax() ? 'render' : 'layout';

        if (empty($values)) {

            $values = array(
                'swimlane_id' => $this->request->getIntegerParam('swimlane_id'),
                'column_id' => $this->request->getIntegerParam('column_id'),
                'color_id' => $this->request->getStringParam('color_id'),
                'owner_id' => $this->request->getIntegerParam('owner_id'),
                'another_task' => $this->request->getIntegerParam('another_task'),
            );
        }

        $this->response->html($this->template->$method('task/new', array(
            'ajax' => $this->request->isAjax(),
            'errors' => $errors,
            'values' => $values + array('project_id' => $project['id']),
            'projects_list' => $this->project->getListByStatus(ProjectModel::ACTIVE),
            'columns_list' => $this->board->getColumnsList($project['id']),
            'users_list' => $this->projectPermission->getMemberList($project['id'], true, false, true),
            'colors_list' => $this->color->getList(),
            'categories_list' => $this->category->getList($project['id']),
            'date_format' => $this->config->get('application_date_format'),
            'date_formats' => $this->dateParser->getAvailableFormats(),
            'title' => $project['name'].' &gt; '.t('New task')
        )));
    }

    /**
     * Validate and save a new task
     *
     * @access public
     */
    public function save()
    {
        $project = $this->getProject();
        $this->generic(
            'validateCreation',
            'create',
            'create',
            'Task created successfully.',
            'Unable to create your task.',
            'saveRedirect',
            array('creator_id' => $this->userSession->getId()),
        );
    }
    
    private function saveRedirect($values)
    {
        $values = $this->request->getValues();
        if (isset($values['another_task']) && $values['another_task'] == 1) {
            unset($values['title']);
            unset($values['description']);
            return '?controller=task&action=create&'.http_build_query($values);
        }
        else {
            $project = $this->getProject();
            return '?controller=board&action=show&project_id='.$project['id'];
        }
    }

    /**
     * Display a form to edit a task
     *
     * @access public
     */
    public function edit(array $values = array(), array $errors = array())
    {
        $task = $this->getTask();
        $ajax = $this->request->isAjax();

        if (empty($values)) {
            $values = $task;
        }

        $this->dateParser->format($values, array('date_due'));

        $params = array(
            'values' => $values,
            'errors' => $errors,
            'task' => $task,
            'users_list' => $this->projectPermission->getMemberList($task['project_id']),
            'colors_list' => $this->color->getList(),
            'categories_list' => $this->category->getList($task['project_id']),
            'date_format' => $this->config->get('application_date_format'),
            'date_formats' => $this->dateParser->getAvailableFormats(),
            'ajax' => $ajax,
        );

        $this->respond('task/edit', $params);
    }

    /**
     * Validate and update a task
     *
     * @access public
     */
    public function update()
    {
        $this->generic(
            'validateModification',
            'update',
            'edit',
            'Task updated successfully.',
            'Unable to update your task.',
            'updateRedirect',
        );
    }
    
    private function updateRedirect()
    {
        $task = $this->getTask();
        if ($this->request->getIntegerParam('ajax')) {
            return '?controller=board&action=show&project_id='.$task['project_id'];
        } else {    
            return '?controller=task&action=show&task_id='.$task['id'].'&project_id='.$task['project_id'];
        }
    }
    
    private function generic($validate_fn, $real_fn, $final_fn, $success_msg, $fail_msg, $redirect_fn, array $extra_values = array())
    {
        $values = $this->request->getValues();
        foreach($extra_values as $k => $v) {
            $values[$k] = $v;
        }

        list($valid, $errors) = $this->task->$validate_fn($values);

        if ($valid) {
            if ($this->task->$real_fn($values)) {
                $this->session->flash(t($success_msg));

                $redirect_url = $this->$redirect_fn();
                if (! is_null($redirect_url)) {
                    $this->response->redirect($redirect_url);
                }
            }
            else {
                $this->session->flashError(t($fail_msg));
            }
        }

        if (! is_null($final_fn)) {
            $this->$final_fn($values, $errors);
        }
    }

    /**
     * Update time tracking information
     *
     * @access public
     */
    public function time()
    {
        $task = $this->getTask();
        $this->generic(
            'validateTimeModification',
            'update',
            null,
            'Task updated successfully.',
            'Unable to update your task.',
            null,
        );
        $this->response->redirect('?controller=task&action=show&task_id='.$task['id'].'&project_id='.$task['project_id']);
    }

    /**
     * Hide a task
     *
     * @access public
     */
    public function close()
    {
        $this->openOrClose('close');
    }

    /**
     * Open a task
     *
     * @access public
     */
    public function open()
    {
        $this->openOrClose('open');
    }

    /**
     * Open or hide a task
     *
     * @access private
     * @param string   $action   The name of the action
     */
    private function openOrClose($action)
    {
        $task = $this->getTask();

        if ($this->request->getStringParam('confirmation') === 'yes') {

            $this->checkCSRFParam();

            if ($this->task->$action($task['id'])) {
                $this->session->flash(t('Task ' . $action . 'd successfully.'));
            } else {
                $this->session->flashError(t('Unable to ' . $action . ' this task.' ));
            }

            $this->response->redirect('?controller=task&action=show&task_id='.$task['id'].'&project_id='.$task['project_id']);
        }

        $this->response->html($this->taskLayout('task/' . $action, array(
            'task' => $task,
        )));
    }

    /**
     * Remove a task
     *
     * @access public
     */
    public function remove()
    {
        $task = $this->getTask();

        if (! $this->task->canRemoveTask($task)) {
            $this->forbidden();
        }

        if ($this->request->getStringParam('confirmation') === 'yes') {

            $this->checkCSRFParam();

            if ($this->task->remove($task['id'])) {
                $this->session->flash(t('Task removed successfully.'));
            } else {
                $this->session->flashError(t('Unable to remove this task.'));
            }

            $this->response->redirect('?controller=board&action=show&project_id='.$task['project_id']);
        }

        $this->response->html($this->taskLayout('task/remove', array(
            'task' => $task,
        )));
    }

    /**
     * Duplicate a task
     *
     * @access public
     */
    public function duplicate()
    {
        $task = $this->getTask();

        if ($this->request->getStringParam('confirmation') === 'yes') {

            $this->checkCSRFParam();
            $task_id = $this->task->duplicate($task['id']);

            if ($task_id) {
                $this->session->flash(t('Task created successfully.'));
                $this->response->redirect('?controller=task&action=show&task_id='.$task_id.'&project_id='.$task['project_id']);
            } else {
                $this->session->flashError(t('Unable to create this task.'));
                $this->response->redirect('?controller=task&action=duplicate&task_id='.$task['id'].'&project_id='.$task['project_id']);
            }
        }

        $this->response->html($this->taskLayout('task/duplicate', array(
            'task' => $task,
        )));
    }

    /**
     * Edit description form
     *
     * @access public
     */
    public function description()
    {
        $task = $this->getTask();
        $ajax = $this->request->isAjax() || $this->request->getIntegerParam('ajax');

        if ($this->request->isPost()) {

            $values = $this->request->getValues();

            list($valid, $errors) = $this->task->validateDescriptionCreation($values);

            if ($valid) {

                if ($this->task->update($values)) {
                    $this->session->flash(t('Task updated successfully.'));
                }
                else {
                    $this->session->flashError(t('Unable to update your task.'));
                }

                if ($ajax) {
                    $this->response->redirect('?controller=board&action=show&project_id='.$task['project_id']);
                }
                else {
                    $this->response->redirect('?controller=task&action=show&task_id='.$task['id'].'&project_id='.$task['project_id']);
                }
            }
        }
        else {
            $values = $task;
            $errors = array();
        }

        $params = array(
            'values' => $values,
            'errors' => $errors,
            'task' => $task,
            'ajax' => $ajax,
        );

        $this->respond('task/edit_description', $params);
    }

    /**
     * Move a task to another project
     *
     * @access public
     */
    public function move()
    {
        $this->moveOrCopy('move', 'update');
    }

    /**
     * Duplicate a task to another project
     *
     * @access public
     */
    public function copy()
    {
        $this->moveOrCopy('duplicate', 'create');
    }

    /**
     * Move or duplicate a task to another project
     *
     * @access private
     * @param string   $action          The name of the action
     * @param string   $action_message  The name of the action to use in the displayed message
     */
    private function moveOrCopy($action, $action_message)
    {
        $task = $this->getTask();
        $values = $task;
        $errors = array();
        $projects_list = $this->projectPermission->getActiveMemberProjects($this->userSession->getId());

        unset($projects_list[$task['project_id']]);

        if ($this->request->isPost()) {

            $values = $this->request->getValues();
            list($valid, $errors) = $this->task->validateProjectModification($values);

            if ($valid) {
                if ($action == 'duplicate') {
                    $task_id = $this->task->duplicateToProject($task['id'], $values['project_id']);
                    $redirect_task_id = $task_id;
                } else {
                    $task_id = $this->task->moveToProject($task['id'], $values['project_id']);
                    $redirect_task_id = $task['id'];
                }
                if ($task_id) {
                    $this->session->flash(t('Task ' . $action_message . 'd successfully.'));
                    $this->response->redirect('?controller=task&action=show&task_id='.$redirect_task_id.'&project_id='.$values['project_id']);
                }
                else {
                    $this->session->flashError(t('Unable to ' . $action_message . ' your task.'));
                }
            }
        }

        $this->response->html($this->taskLayout('task/' . $action . '_project', array(
            'values' => $values,
            'errors' => $errors,
            'task' => $task,
            'projects_list' => $projects_list,
        )));
    }
    
    private function respond($path, $params)
    {
        if ($params['ajax']) {
            $this->response->html($this->template->render($path, $params));
        }
        else {
            $this->response->html($this->taskLayout($path, $params));
        }
    }
}
