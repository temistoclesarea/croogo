<?php

namespace Croogo\Comments\Controller;

use App\Network\Email\Email;
use Cake\Event\Event;
use Croogo\Comments\Model\Entity\Comment;

/**
 * Comments Controller
 *
 * @category Controller
 * @package  Croogo.Comments.Controller
 * @version  1.0
 * @author   Fahad Ibnay Heylaal <contact@fahad19.com>
 * @license  http://www.opensource.org/licenses/mit-license.php The MIT License
 * @link     http://www.croogo.org
 */
class CommentsController extends AppController
{

/**
 * Preset Variable Search
 * @var array
 */
    public $presetVars = true;

    public function initialize()
    {
        parent::initialize();

        $this->loadCroogoComponents(['Akismet', 'BulkProcess', 'Recaptcha']);
        $this->_setupPrg();
    }

/**
 * index
 *
 * @return void
 * @access public
 */
    public function index()
    {
        $this->set('title_for_layout', __d('croogo', 'Comments'));

        if (!isset($this->request['ext']) ||
            $this->request['ext'] != 'rss') {
            return $this->redirect('/');
        }

        $this->paginate = [
            'conditions' => [
                'status' => $this->Comment->status('approval')
            ],
            'order' => [
                'weight' => 'DESC',
            ],
            'limit' => Configure::read('Comment.feed_limit')
        ];

        $this->set('comments', $this->paginate($query));
    }

/**
 * add
 *
 * @param int$foreignKey
 * @param int$parentId
 * @return void
 * @access public
 * @throws UnexpectedValueException
 */
    public function add($model, $foreignKey = null, $parentId = null)
    {
        if (!$foreignKey) {
            $this->Flash->error(__d('croogo', 'Invalid id'));
            return $this->redirect('/');
        }

        if (empty($this->Comment->{$model})) {
            throw new UnexpectedValueException(
                sprintf('%s not configured for Comments', $model)
            );
        }

        $Model = $this->Comment->{$model};
        $data = $Model->find('first', [
            'conditions' => [
                $Model->escapeField($Model->primaryKey) => $foreignKey,
                $Model->escapeField('status') => $Model->status('approval'),
            ],
        ]);

        if (isset($data[$Model->alias]['path'])) {
            $redirectUrl = $data[$Model->alias]['path'];
        } else {
            $redirectUrl = $this->referer();
        }

        if (!is_null($parentId) && !$this->Comment->isValidLevel($parentId)) {
            $this->Flash->error(__d('croogo', 'Maximum level reached. You cannot reply to that comment.'));
            return $this->redirect($redirectUrl);
        }

        $typeSetting = $Model->getTypeSetting($data);
        extract(array_intersect_key($typeSetting, [
            'commentable' => null,
            'autoApprove' => null,
            'spamProtection' => null,
            'captchaProtection' => null,
            ]));
        $continue = $commentable && $data[$Model->alias]['comment_status'];

        if (!$continue) {
            $this->Flash->error(__d('croogo', 'Comments are not allowed.'));
            return $this->redirect($redirectUrl);
        }

        // spam protection and captcha
        $continue = $this->_spamProtection($continue, $spamProtection, $data);
        $continue = $this->_captcha($continue, $captchaProtection, $data);
        $success = false;
        if (!empty($this->request->data) && $continue === true) {
            $comment = $this->request->data;
            $comment['Comment']['ip'] = env('REMOTE_ADDR');
            $comment['Comment']['status'] = $autoApprove ? CroogoStatus::APPROVED : CroogoStatus::PENDING;
            $userData = [];
            if ($this->Auth->user()) {
                $userData['User'] = $this->Auth->user();
            }

            $options = [
                'parentId' => $parentId,
                'userData' => $userData,
            ];
            $success = $this->Comment->add($comment, $model, $foreignKey, $options);
            if ($success) {
                if ($autoApprove) {
                    $messageFlash = __d('croogo', 'Your comment has been added successfully.');
                } else {
                    $messageFlash = __d('croogo', 'Your comment will appear after moderation.');
                }
                $this->Flash->success($messageFlash);

                if (Configure::read('Comment.email_notification')) {
                    $this->_sendEmail($data, $comment);
                }

                return $this->redirect(Router::url($data[$Model->alias]['url'], true) . '#comment-' . $this->Comment->id);
            }
        }

        $this->set(compact('success', 'data', 'type', 'model', 'foreignKey', 'parentId'));
    }

/**
 * Spam Protection
 *
 * @param bool$continue
 * @param bool$spamProtection
 * @param array $node
 * @return boolean
 * @access protected
 * @deprecated This method will be renamed to _spamProtection() in the future
 */
    protected function _spamProtection($continue, $spamProtection, $node)
    {
        if (!empty($this->request->data) &&
            $spamProtection &&
            $continue === true) {
            $this->Akismet->setCommentAuthor($this->request->data['Comment']['name']);
            $this->Akismet->setCommentAuthorEmail($this->request->data['Comment']['email']);
            $this->Akismet->setCommentAuthorURL($this->request->data['Comment']['website']);
            $this->Akismet->setCommentContent($this->request->data['Comment']['body']);
            if ($this->Akismet->isCommentSpam()) {
                $continue = false;
                $this->Flash->error(__d('croogo', 'Sorry, the comment appears to be spam.'));
            }
        }

        return $continue;
    }

/**
 * Captcha
 *
 * @param bool$continue
 * @param bool$captchaProtection
 * @param array $node
 * @return boolean
 * @access protected
 */
    protected function _captcha($continue, $captchaProtection, $node)
    {
        if (!empty($this->request->data) &&
            $captchaProtection &&
            $continue === true &&
            !$this->Recaptcha->valid($this->request)) {
            $continue = false;
            $this->Flash->error(__d('croogo', 'Invalid captcha entry'));
        }

        return $continue;
    }

/**
 * sendEmail
 *
 * @param array $node Node data
 * @param array $comment Comment data
 * @return void
 * @access protected
 */
    protected function _sendEmail($node, $data)
    {
        $email = new Email();
        $commentId = $this->Comment->id;
        try {
            $email->from(Configure::read('Site.title') . ' ' .
                '<croogo@' . preg_replace('#^www\.#', '', strtolower($_SERVER['SERVER_NAME'])) . '>')
                ->to(Configure::read('Site.email'))
                ->subject('[' . Configure::read('Site.title') . '] ' .
                    __d('croogo', 'New comment posted under') . ' ' . $node['Node']['title'])
                ->viewVars(compact('node', 'data', 'commentId'))
                ->template('Comments.comment');
            if ($this->theme) {
                $email->theme($this->theme);
            }
            return $email->send();
        } catch (SocketException $e) {
            $this->log(sprintf('Error sending comment notification: %s', $e->getMessage()));
        }
    }

/**
 * delete
 *
 * @param int$id
 * @return void
 * @access public
 */
    public function delete($id)
    {
        $success = 0;
        if ($this->Session->check('Auth.User.id')) {
            $userId = $this->Session->read('Auth.User.id');
            $comment = $this->Comment->find('first', [
                'conditions' => [
                    'Comment.id' => $id,
                    'Comment.user_id' => $userId,
                ],
            ]);

            if (isset($comment['Comment']['id']) &&
                $this->Comment->delete($id)) {
                $success = 1;
            }
        }

        $this->set(compact('success'));
    }
}
