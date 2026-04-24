import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';

Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowAlert: true,
    shouldPlaySound: true,
    shouldSetBadge: false,
  }),
});

export type BookingNotificationData = {
  type?: unknown;
  booking_id?: unknown;
  location_id?: unknown;
  date?: unknown;
};

export async function resolveExpoPushToken() {
  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync('bookings', {
      name: 'Bookinger',
      importance: Notifications.AndroidImportance.MAX,
      vibrationPattern: [0, 250, 250, 250],
      lightColor: '#5e7097',
    });
  }

  if (!Device.isDevice) {
    return null;
  }

  const projectId = resolveProjectId();

  if (!projectId) {
    return null;
  }

  const existingPermission = await Notifications.getPermissionsAsync();
  let finalStatus = existingPermission.status;

  if (existingPermission.status !== 'granted') {
    const requestedPermission = await Notifications.requestPermissionsAsync();
    finalStatus = requestedPermission.status;
  }

  if (finalStatus !== 'granted') {
    return null;
  }

  const token = await Notifications.getExpoPushTokenAsync({ projectId });

  return token.data;
}

export function addBookingNotificationResponseListener(
  listener: (data: BookingNotificationData) => void,
) {
  return Notifications.addNotificationResponseReceivedListener((response) => {
    const data = response.notification.request.content.data as BookingNotificationData;

    if (data?.type === 'booking_created') {
      listener(data);
    }
  });
}

export function addBookingNotificationReceivedListener(
  listener: (data: BookingNotificationData) => void,
) {
  return Notifications.addNotificationReceivedListener((notification) => {
    const data = notification.request.content.data as BookingNotificationData;

    if (data?.type === 'booking_created') {
      listener(data);
    }
  });
}

function resolveProjectId() {
  const extra = Constants.expoConfig?.extra as { eas?: { projectId?: string }; projectId?: string } | undefined;

  return extra?.eas?.projectId
    ?? extra?.projectId
    ?? Constants.easConfig?.projectId
    ?? null;
}
