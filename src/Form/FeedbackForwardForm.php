<?php

namespace Drupal\kififeedback\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\kififeedback\LogEntryInterface;

class FeedbackForwardForm extends FeedbackAdminFormBase {
  const REPLY_NO_REPLY = 0;
  const REPLY_TO_USER = 1;
  const REPLY_TO_ADMIN = 2;

  public function form(array $form, FormStateInterface $form_state) {
    $feedback = $this->entity;
    $form = parent::form($form, $form_state);

    $form['comment']['#access'] = FALSE;

    $form['forward_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Recipient'),
      '#required' => TRUE,
    ];

    $form['forward_message'] = [
      '#type' => 'text_format',
      '#format' => 'basic_html_without_ckeditor',
      '#title' => $this->t('Message'),
      '#default_value' => $this->t('Forwarding feedback originally sent to [site:name].', [], [
        'langcode' => $feedback->language()->getId()
      ]),
      '#required' => TRUE,
    ];

    $form['reply_to'] = [
      '#type' => 'select',
      '#title' => $this->t('Reply address'),
      '#description' => $this->t('Can be used to change reply-to address for email replies.'),
      '#options' => [
        $this->currentUser()->getEmail() => $this->currentUser()->getEmail(),
      ],
    ];

    if ($email = $feedback->getEmail()) {
      $form['reply_to']['#options'][$email] = $email;
    }

    return $form;
  }

  public function sendMessage(array $form, FormStateInterface $form_state) {
    $forward_email = $form_state->getValue('forward_email');
    $result = $this->entityManager->getStorage('user')->loadByProperties(['mail' => $forward_email]);
    $user = reset($result);

    $reply_email = $form_state->getValue('reply_to');

    if ($reply_email == $this->currentUser()->getEmail()) {
      $reply_email = NULL;
    }

    $log_entry = $this->entityManager->getStorage('kififeedback_log')->create([
      'action' => LogEntryInterface::ACTION_FORWARD,
      'forward_email' => $forward_email,
      'forward_user' => $user ?: NULL,
      'message' => $form_state->getValue('forward_message'),
    ]);

    $this->entity->addActionToLog($log_entry);

    $langcode = $this->entity->language()->getId();
    $this->mailer->mail('kififeedback', 'forward', $user ?: $forward_email, $langcode, [
      'from' => $this->currentUser(),
      'kififeedback' => $this->entity,
    ], $reply_email);

    drupal_set_message($this->t('Feedback forwarded to @email', ['@email' => $forward_email]));
  }

  public function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    $actions['delete']['#access'] = FALSE;
    $actions['submit']['#value'] = $this->t('Send');

    $pos = array_search('::save', $actions['submit']['#submit']);
    array_splice($actions['submit']['#submit'], $pos, 0, '::sendMessage');

    return $actions;
  }
}
