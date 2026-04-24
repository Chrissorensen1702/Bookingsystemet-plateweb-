import Constants from 'expo-constants';

type ExpoConfigExtra = {
  baseUrl?: string;
  enablePushNotifications?: boolean;
};

const extra = (Constants.expoConfig?.extra ?? {}) as ExpoConfigExtra;

export const appConfig = {
  baseUrl: stripTrailingSlash(extra.baseUrl ?? 'https://login.platebook.dk'),
  enablePushNotifications: extra.enablePushNotifications === true,
};

export function apiUrl(path: string) {
  const normalizedPath = path.startsWith('/') ? path : `/${path}`;

  return `${appConfig.baseUrl}/api/native${normalizedPath}`;
}

export function nativeAppUrl(path = '/login') {
  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  const separator = normalizedPath.includes('?') ? '&' : '?';

  return `${appConfig.baseUrl}${normalizedPath}${separator}native_app=1`;
}

function stripTrailingSlash(value: string) {
  return value.replace(/\/+$/, '');
}
