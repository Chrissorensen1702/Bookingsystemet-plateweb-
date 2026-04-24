const LOCAL_BASE_URL = 'http://192.168.1.193/bookingsystem/public';

function stripTrailingSlash(value) {
  return String(value || '').replace(/\/+$/, '');
}

function resolveBaseUrl(config) {
  return stripTrailingSlash(
    process.env.EXPO_PUBLIC_API_BASE_URL
      || process.env.PLATEBOOK_API_BASE_URL
      || config.extra?.baseUrl
      || LOCAL_BASE_URL
  );
}

module.exports = ({ config }) => {
  const baseUrl = resolveBaseUrl(config);
  const infoPlist = { ...(config.ios?.infoPlist || {}) };

  if (baseUrl.startsWith('https://')) {
    delete infoPlist.NSAppTransportSecurity;
  }

  return {
    ...config,
    extra: {
      ...(config.extra || {}),
      baseUrl,
    },
    ios: {
      ...(config.ios || {}),
      infoPlist,
    },
  };
};
