const DEFAULT_BASE_URL = 'https://login.platebook.dk';

function stripTrailingSlash(value) {
  return String(value || '').replace(/\/+$/, '');
}

function resolveBaseUrl(config) {
  return stripTrailingSlash(
    process.env.EXPO_PUBLIC_API_BASE_URL
      || process.env.PLATEBOOK_API_BASE_URL
      || config.extra?.baseUrl
      || DEFAULT_BASE_URL
  );
}

function resolveBoolean(value, fallback = false) {
  if (typeof value === 'boolean') {
    return value;
  }

  if (typeof value === 'string') {
    return ['1', 'true', 'yes', 'on'].includes(value.toLowerCase());
  }

  return fallback;
}

module.exports = ({ config }) => {
  const baseUrl = resolveBaseUrl(config);
  const easProjectId = process.env.EXPO_PUBLIC_EAS_PROJECT_ID
    || process.env.EAS_PROJECT_ID
    || config.extra?.eas?.projectId
    || null;
  const infoPlist = { ...(config.ios?.infoPlist || {}) };
  const entitlements = { ...(config.ios?.entitlements || {}) };
  const enablePushNotifications = resolveBoolean(
    process.env.EXPO_PUBLIC_ENABLE_PUSH_NOTIFICATIONS
      ?? process.env.PLATEBOOK_ENABLE_PUSH_NOTIFICATIONS
      ?? config.extra?.enablePushNotifications,
    false
  );

  if (baseUrl.startsWith('https://')) {
    delete infoPlist.NSAppTransportSecurity;
  }

  if (enablePushNotifications) {
    entitlements['aps-environment'] = entitlements['aps-environment'] || 'development';
    infoPlist.UIBackgroundModes = Array.from(new Set([
      ...(infoPlist.UIBackgroundModes || []),
      'remote-notification',
    ]));
  } else {
    delete entitlements['aps-environment'];

    if (Array.isArray(infoPlist.UIBackgroundModes)) {
      const backgroundModes = infoPlist.UIBackgroundModes.filter((mode) => mode !== 'remote-notification');

      if (backgroundModes.length > 0) {
        infoPlist.UIBackgroundModes = backgroundModes;
      } else {
        delete infoPlist.UIBackgroundModes;
      }
    }
  }

  return {
    ...config,
    extra: {
      ...(config.extra || {}),
      baseUrl,
      enablePushNotifications,
      ...(easProjectId ? {
        eas: {
          ...(config.extra?.eas || {}),
          projectId: easProjectId,
        },
      } : {}),
    },
    ios: {
      ...(config.ios || {}),
      entitlements,
      infoPlist,
    },
  };
};
