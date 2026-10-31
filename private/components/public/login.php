<section class="login-screen" id="loginScreen" hidden>
    <form class="login-fields login-fields-modern" id="loginForm">
      <div class="login-form-inner">
        <div class="login-language"><select id="loginLanguage" aria-label="Language"><option value="en">English</option><option value="km">ភាសាខ្មែរ</option><option value="zh-CN">简体中文</option></select></div>
        <div class="login-title"><img src="assets/wg-logo.png" alt="WG"><strong>Admin System</strong></div>
        <label class="login-input-row">
          <span><svg class="sr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 22a8 8 0 0 1 16 0"/></svg></span>
          <input type="text" name="email" autocomplete="username" placeholder="Username or email" required aria-label="Username or email">
        </label>
        <label class="login-input-row">
          <span><svg class="sr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span>
          <input id="adminLoginPassword" type="password" name="password" autocomplete="current-password" placeholder="Password" required aria-label="Password">
          <button class="login-password-toggle" id="adminPasswordToggle" type="button" aria-label="Show password"><svg class="sr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button>
        </label>
        <div class="login-options"><label class="remember"><input id="rememberAdminUsername" type="checkbox" name="remember"> Remember username</label></div>
        <button class="login-submit" type="submit">Login</button>
        <p class="login-error" id="loginError"></p>
        <div class="login-divider"><span>Or log in as follows</span></div>
        <button class="login-passkey" type="button" title="Passkey sign-in is not configured"><span>♙</span><b>Passkey</b></button>
        <p class="login-help">If you forget your password or need two-step verification,<br>contact the internal administrator. If the issue remains,<br>contact <a href="mailto:support@wgsystem.local">Technical Support</a>.</p>
      </div>
    </form>
</section>
