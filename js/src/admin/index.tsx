import app from 'flarum/admin/app';
import isExtensionEnabled from 'flarum/admin/utils/isExtensionEnabled';

export default app.initializers.add('adminlby-anonymous', () => {
  const t = (key: string) => app.translator.trans(`adminlby-anonymous.admin.${key}`);
  const extension = app.registry.for('adminlby-anonymous');
  for (const key of ['enabled', 'discussions', 'replies', 'quotes', 'other', 'showOwn']) {
    extension.registerSetting({setting: `adminlby-anonymous.${key}`, type: 'boolean', label: t(`settings.${key}`), default: '1'});
  }
  extension.registerSetting({
    setting: 'adminlby-anonymous.editHistoryChoice', type: 'boolean', label: t('settings.editHistoryChoice'),
    help: t(isExtensionEnabled('huseyinfiliz-diff') ? 'settings.editHistoryChoice_help' : 'settings.editHistoryChoice_requires_diff'),
    disabled: !isExtensionEnabled('huseyinfiliz-diff'), default: '0',
  });
  extension.registerSetting({
    setting: 'adminlby-anonymous.mode', type: 'select', label: t('settings.mode'), help: t('settings.mode_help'),
    options: {ask: t('settings.mode_ask'), real: t('settings.mode_real'), anonymous: t('settings.mode_anonymous')}, default: 'ask',
  });
  extension.registerPermission({icon: 'fas fa-user-secret', label: t('permissions.start'), permission: 'anonymous.startDiscussion'}, 'start');
  extension.registerPermission({icon: 'fas fa-user-secret', label: t('permissions.reply'), permission: 'discussion.anonymousReply'}, 'reply');
  extension.registerPermission({icon: 'fas fa-user-shield', label: t('permissions.reveal'), permission: 'anonymous.reveal'}, 'moderate');
  extension.registerPermission({icon: 'fas fa-user-secret', label: t('permissions.changeIdentity'), permission: 'anonymous.changeIdentity'}, 'moderate');
});
