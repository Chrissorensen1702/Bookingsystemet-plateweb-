export type User = {
  id: number;
  name: string;
  email: string;
  initials: string;
  role_label: string;
  profile_photo_url?: string | null;
  permissions?: Record<string, boolean>;
};

export type Tenant = {
  id: number;
  name: string;
  slug?: string;
  timezone?: string;
};

export type Location = {
  id: number;
  name: string;
  slug: string;
  timezone: string;
  city?: string | null;
};

export type Booking = {
  id: number;
  customer: string;
  customer_email?: string | null;
  customer_phone?: string | null;
  service: string;
  service_color: string;
  staff_name: string;
  location_name: string;
  starts_at: string;
  ends_at: string;
  time_range: string;
  status: string;
  notes?: string | null;
};

export type Service = {
  id: number;
  name: string;
  category: string;
  duration_minutes: number;
  price_minor?: number | null;
  color: string;
  online_bookable: boolean;
};

export type StaffMember = {
  id: number;
  name: string;
  initials: string;
  profile_photo_url?: string | null;
  service_ids?: number[];
};

export type CalendarInterval = {
  start_minutes: number;
  end_minutes: number;
};

export type CalendarGrid = {
  start_minutes: number;
  end_minutes: number;
  opening_intervals: CalendarInterval[];
  work_shift_intervals: CalendarInterval[];
};

export type WorkShiftSummary = {
  id: number;
  starts_at: string;
  ends_at: string;
  time_range: string;
  work_role: string;
  work_role_label: string;
  countdown_label: string;
  countdown_target: string;
  location?: Location | null;
};

export type BootstrapPayload = {
  user: User;
  tenant: Tenant;
  locations: Location[];
  default_location_id?: number | null;
};

export type BookingsPayload = {
  date: string;
  location_id: number;
  bookings: Booking[];
  next_booking?: Booking | null;
  has_work_shift_for_date?: boolean;
  work_shift_location?: Location | null;
  next_work_shift?: WorkShiftSummary | null;
  calendar_grid?: CalendarGrid | null;
  services: Service[];
  staff: StaffMember[];
};

export type BookingOptionsPayload = {
  booking_date: string;
  location_id: number;
  staff: StaffMember[];
  services: Service[];
};
