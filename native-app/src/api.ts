import { apiUrl } from './config';
import { Booking, BookingOptionsPayload, BookingsPayload, BootstrapPayload, Service, Tenant, User } from './types';

export type LoginPayload = {
  token: string;
  expires_at?: string | null;
  user: User;
  tenant?: Tenant | null;
};

export type CreateBookingPayload = {
  location_id: number;
  staff_user_id: number;
  service_id: number;
  booking_date: string;
  booking_time: string;
  customer_name: string;
  customer_email?: string | null;
  customer_phone?: string | null;
  notes?: string | null;
};

type RequestOptions = {
  method?: string;
  token?: string | null;
  body?: unknown;
  query?: Record<string, string | number | null | undefined>;
};

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
  ) {
    super(message);
  }
}

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const url = makeUrl(path, options.query);
  const headers: Record<string, string> = {
    Accept: 'application/json',
  };

  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  if (options.token) {
    headers.Authorization = `Bearer ${options.token}`;
  }

  const response = await fetch(url, {
    method: options.method ?? 'GET',
    headers,
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
  });

  const payload = await readPayload(response);

  if (!response.ok) {
    const message = typeof payload?.message === 'string'
      ? payload.message
      : 'Der opstod en fejl. Prøv igen.';

    throw new ApiError(message, response.status);
  }

  return payload as T;
}

export function login(email: string, password: string) {
  return apiRequest<LoginPayload>('/login', {
    method: 'POST',
    body: {
      email,
      password,
      device_name: 'PlateBook iPhone',
    },
  });
}

export function fetchBootstrap(token: string) {
  return apiRequest<BootstrapPayload>('/bootstrap', { token });
}

export function fetchBookings(token: string, date: string, locationId: number) {
  return apiRequest<BookingsPayload>('/bookings', {
    token,
    query: {
      date,
      location_id: locationId,
      status: 'active',
    },
  });
}

export function fetchServices(token: string, locationId: number) {
  return apiRequest<{ location_id: number; services: Service[] }>('/services', {
    token,
    query: {
      location_id: locationId,
    },
  });
}

export function fetchBookingOptions(token: string, date: string, locationId: number) {
  return apiRequest<BookingOptionsPayload>('/booking-options', {
    token,
    query: {
      booking_date: date,
      location_id: locationId,
    },
  });
}

export function createBooking(token: string, payload: CreateBookingPayload) {
  return apiRequest<{ message: string; booking: Booking }>('/bookings', {
    method: 'POST',
    token,
    body: payload,
  });
}

export function logout(token: string) {
  return apiRequest<{ message: string }>('/logout', {
    method: 'DELETE',
    token,
  });
}

function makeUrl(path: string, query?: RequestOptions['query']) {
  const url = new URL(apiUrl(path));

  Object.entries(query ?? {}).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') {
      url.searchParams.set(key, String(value));
    }
  });

  return url.toString();
}

async function readPayload(response: Response): Promise<any> {
  const text = await response.text();

  if (text.trim() === '') {
    return {};
  }

  try {
    return JSON.parse(text);
  } catch {
    return {
      message: text,
    };
  }
}
