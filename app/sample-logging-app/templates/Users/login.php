<?php
/**
 * @var \App\View\AppView $this
 */
?>
<div class="auth-form" style="max-width: 420px; margin: 40px auto;">
    <h2>🔐 Login</h2>
    <p class="note" style="color:#7a7a7a">Log in so your actions (product changes, etc.) are attributed to you in the audit trail.</p>
    <?= $this->Form->create(null) ?>
    <?= $this->Form->control('email', ['type' => 'email', 'required' => true, 'label' => 'Email']) ?>
    <?= $this->Form->control('password', ['type' => 'password', 'required' => true, 'label' => 'Password']) ?>
    <?= $this->Form->button('Login', ['class' => 'button']) ?>
    <?= $this->Form->end() ?>

    <div style="margin-top:24px;padding:12px;background:#f4f5f6;border-radius:6px;font-size:13px;color:#555">
        <strong>Demo users</strong> (seeded):<br>
        alice@example.com / password123<br>
        bob@example.com / password123<br>
        admin@example.com / admin123
    </div>
</div>
