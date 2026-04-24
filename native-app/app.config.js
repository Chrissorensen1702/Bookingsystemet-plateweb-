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

module.exports = ({ config }) => {
  const baseUrl = resolveBaseUrl(config);
  const easProjectId = process.env.EXPO_PUBLIC_EAS_PROJECT_ID
    || process.env.EAS_PROJECT_ID
    || config.extra?.eas?.projectId
    || null;
  const infoPlist = { ...(config.ios?.infoPlist || {}) };

  if (baseUrl.startsWith('https://')) {
    delete infoPlist.NSAppTransportSecurity;
  }

  return {
    ...config,
    extra: {
      ...(config.extra || {}),
      baseUrl,
      ...(easProjectId ? {
        eas: {
          ...(config.extra?.eas || {}),
          projectId: easProjectId,
        },
      } : {}),
    },
    ios: {
      ...(config.ios || {}),
      infoPlist,
    },
  };
};
