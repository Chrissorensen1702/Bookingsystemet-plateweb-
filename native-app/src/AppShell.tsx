import { Ionicons } from '@expo/vector-icons';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { StatusBar } from 'expo-status-bar';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  Image,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';

import {
  ApiError,
  CreateBookingPayload,
  createBooking,
  fetchBookingOptions,
  fetchBookings,
  fetchBootstrap,
  fetchServices,
  login,
  logout,
  registerPushToken,
} from './api';
import { appConfig } from './config';
import { addBookingNotificationReceivedListener, addBookingNotificationResponseListener, resolveExpoPushToken } from './notifications';
import { Booking, BookingOptionsPayload, BookingsPayload, BootstrapPayload, CalendarGrid, CalendarInterval, Location, Service, WorkShiftSummary } from './types';

const TOKEN_KEY = 'platebook.native.token';
const SLOT_MINUTES = 15;
const SLOT_HEIGHT = 32;
const TIME_LABEL_WIDTH = 52;
const TAB_BAR_HEIGHT = 52;
const LOCATION_DROPDOWN_GAP = 8;
const LOCATION_DROPDOWN_ITEM_HEIGHT = 46;
const colors = {
  ink: '#0e1833',
  text: '#213147',
  muted: '#59647d',
  primary: '#5e7097',
  primaryStrong: '#4d668c',
  primarySoft: '#e8eef7',
  accent: '#4d668c',
  accentSoft: '#e4ebf5',
  border: '#d7dfec',
  bg: '#f4f6fb',
  surface: '#ffffff',
  surfaceAlt: '#eef2f8',
  headerSurface: '#e4ebf5',
  gridLine: '#b8c4d3',
  gridHeader: '#e3ebf5',
  gridTime: '#eef3f9',
  now: '#ea580c',
  danger: '#b73d3d',
  dangerDeep: '#8c2f2f',
  dangerSoft: '#f8eaea',
  successSoft: '#e8eef7',
  placeholder: '#8b95aa',
};

type TabKey = 'calendar' | 'messages' | 'shifts' | 'more';
type IconName = React.ComponentProps<typeof Ionicons>['name'];

const tabs: Array<{ key: TabKey; label: string; icon: IconName }> = [
  { key: 'calendar', label: 'Kalender', icon: 'calendar-outline' },
  { key: 'messages', label: 'Beskeder', icon: 'chatbubbles-outline' },
  { key: 'shifts', label: 'Vagter', icon: 'time-outline' },
  { key: 'more', label: 'Mere', icon: 'menu-outline' },
];

const weekdayLabels = ['Man', 'Tir', 'Ons', 'Tor', 'Fre', 'Lør', 'Søn'];

const headerTitles: Record<TabKey, string> = {
  calendar: 'Min kalender',
  messages: 'Mine beskeder',
  shifts: 'Mine vagter',
  more: 'Mere',
};

export function AppShell() {
  const insets = useSafeAreaInsets();
  const [token, setToken] = useState<string | null>(null);
  const [boot, setBoot] = useState<BootstrapPayload | null>(null);
  const [activeTab, setActiveTab] = useState<TabKey>('calendar');
  const [selectedDate, setSelectedDate] = useState(todayIso());
  const [selectedLocationId, setSelectedLocationId] = useState<number | null>(null);
  const [bookings, setBookings] = useState<Booking[]>([]);
  const [nextBooking, setNextBooking] = useState<Booking | null>(null);
  const [nextWorkShift, setNextWorkShift] = useState<WorkShiftSummary | null>(null);
  const [hasWorkShiftForDate, setHasWorkShiftForDate] = useState(true);
  const [calendarGrid, setCalendarGrid] = useState<CalendarGrid | null>(null);
  const [services, setServices] = useState<Service[]>([]);
  const [isCreateBookingOpen, setIsCreateBookingOpen] = useState(false);
  const [isBooting, setIsBooting] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const selectedLocation = useMemo(
    () => boot?.locations.find((location) => location.id === selectedLocationId) ?? boot?.locations[0] ?? null,
    [boot?.locations, selectedLocationId],
  );

  const signOut = useCallback(async () => {
    const currentToken = token;

    setToken(null);
    setBoot(null);
    setBookings([]);
    setNextBooking(null);
    setNextWorkShift(null);
    setHasWorkShiftForDate(true);
    setCalendarGrid(null);
    setServices([]);
    setSelectedLocationId(null);
    setNotice(null);
    await AsyncStorage.removeItem(TOKEN_KEY);

    if (currentToken) {
      logout(currentToken).catch(() => undefined);
    }
  }, [token]);

  const handleApiError = useCallback((caught: unknown) => {
    if (caught instanceof ApiError && caught.status === 401) {
      signOut();
      return;
    }

    setError(caught instanceof Error ? caught.message : 'Der opstod en fejl.');
  }, [signOut]);

  const applyBookingPayload = useCallback((payload: BookingsPayload) => {
    setBookings(payload.bookings);
    setNextBooking(payload.next_booking ?? null);
    setNextWorkShift(payload.next_work_shift ?? null);
    setHasWorkShiftForDate(payload.has_work_shift_for_date ?? true);
    setCalendarGrid(payload.calendar_grid ?? null);
    setServices(payload.services);
  }, []);

  const switchToWorkShiftLocation = useCallback((payload: BookingsPayload, currentLocationId: number, date: string) => {
    const shiftLocation = payload.work_shift_location;

    if (payload.has_work_shift_for_date !== false || !shiftLocation || shiftLocation.id === currentLocationId) {
      return false;
    }

    setSelectedLocationId(shiftLocation.id);
    setNotice(`Du er vagtplaneret i ${shiftLocation.name} den ${formatShortDate(date)}, så kalenderen er skiftet dertil.`);

    return true;
  }, []);

  const loadBootstrap = useCallback(async (authToken: string) => {
    const payload = await fetchBootstrap(authToken);
    const defaultLocationId = payload.default_location_id ?? payload.locations[0]?.id ?? null;

    setBoot(payload);
    setSelectedLocationId((current) => current ?? defaultLocationId);

    if (defaultLocationId) {
      const [bookingPayload, servicePayload] = await Promise.all([
        fetchBookings(authToken, selectedDate, defaultLocationId),
        fetchServices(authToken, defaultLocationId),
      ]);

      if (switchToWorkShiftLocation(bookingPayload, defaultLocationId, selectedDate)) {
        setBookings([]);
        setNextBooking(null);
        setNextWorkShift(null);
        setHasWorkShiftForDate(true);
        setCalendarGrid(null);
        setServices([]);
        return;
      }

      applyBookingPayload(bookingPayload);
      setServices(servicePayload.services);
    } else {
      setBookings([]);
      setNextBooking(null);
      setNextWorkShift(null);
      setHasWorkShiftForDate(true);
      setCalendarGrid(null);
      setServices([]);
    }
  }, [applyBookingPayload, selectedDate, switchToWorkShiftLocation]);

  const refreshCurrentData = useCallback(async (showRefreshIndicator = false) => {
    if (!token || !selectedLocationId) {
      return;
    }

    if (showRefreshIndicator) {
      setIsRefreshing(true);
    }

    setError(null);

    try {
      if (activeTab === 'more') {
        const servicePayload = await fetchServices(token, selectedLocationId);
        setServices(servicePayload.services);
      } else if (activeTab === 'calendar') {
        const bookingPayload = await fetchBookings(token, selectedDate, selectedLocationId);

        if (switchToWorkShiftLocation(bookingPayload, selectedLocationId, selectedDate)) {
          return;
        }

        applyBookingPayload(bookingPayload);
      }
    } catch (caught) {
      handleApiError(caught);
    } finally {
      if (showRefreshIndicator) {
        setIsRefreshing(false);
      }
    }
  }, [activeTab, applyBookingPayload, handleApiError, selectedDate, selectedLocationId, switchToWorkShiftLocation, token]);

  const handleCreateBooking = useCallback(async (payload: CreateBookingPayload) => {
    if (!token) {
      return;
    }

    setError(null);

    await createBooking(token, payload);

    if (selectedLocationId !== payload.location_id) {
      setSelectedLocationId(payload.location_id);
    }

    if (selectedDate !== payload.booking_date) {
      setSelectedDate(payload.booking_date);
    }

    const bookingPayload = await fetchBookings(token, payload.booking_date, payload.location_id);
    applyBookingPayload(bookingPayload);
  }, [applyBookingPayload, selectedDate, selectedLocationId, token]);

  const handleLoadBookingOptions = useCallback(async (date: string, locationId: number) => {
    if (!token) {
      return {
        booking_date: date,
        location_id: locationId,
        staff: [],
        services: [],
      };
    }

    return fetchBookingOptions(token, date, locationId);
  }, [token]);

  useEffect(() => {
    let mounted = true;

    AsyncStorage.getItem(TOKEN_KEY)
      .then(async (storedToken) => {
        if (!mounted || !storedToken) {
          return;
        }

        setToken(storedToken);
        await loadBootstrap(storedToken);
      })
      .catch((caught) => {
        if (mounted) {
          handleApiError(caught);
        }
      })
      .finally(() => {
        if (mounted) {
          setIsBooting(false);
        }
      });

    return () => {
      mounted = false;
    };
  }, [handleApiError, loadBootstrap]);

  useEffect(() => {
    if (token && selectedLocationId) {
      refreshCurrentData();
    }
  }, [selectedDate, selectedLocationId]);

  useEffect(() => {
    if (!notice) {
      return undefined;
    }

    const timer = setTimeout(() => setNotice(null), 3600);

    return () => clearTimeout(timer);
  }, [notice]);

  useEffect(() => {
    if (!token) {
      return undefined;
    }

    let cancelled = false;
    const platform = Platform.OS === 'ios' || Platform.OS === 'android' ? Platform.OS : 'unknown';

    resolveExpoPushToken()
      .then(async (pushToken) => {
        if (cancelled || !pushToken) {
          return;
        }

        await registerPushToken(token, pushToken, platform);
      })
      .catch((caught) => {
        if (!cancelled) {
          setNotice(caught instanceof Error
            ? `Notifikationer kunne ikke aktiveres: ${caught.message}`
            : 'Notifikationer kunne ikke aktiveres.');
        }
      });

    return () => {
      cancelled = true;
    };
  }, [token]);

  useEffect(() => {
    const subscription = addBookingNotificationResponseListener((data) => {
      const notificationDate = typeof data.date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(data.date)
        ? data.date
        : null;
      const notificationLocationId = Number(data.location_id);

      setActiveTab('calendar');

      if (notificationDate) {
        setSelectedDate(notificationDate);
      }

      if (Number.isFinite(notificationLocationId) && notificationLocationId > 0) {
        setSelectedLocationId(notificationLocationId);
      }
    });

    return () => subscription.remove();
  }, []);

  useEffect(() => {
    const subscription = addBookingNotificationReceivedListener(() => {
      if (token && selectedLocationId) {
        refreshCurrentData();
      }
    });

    return () => subscription.remove();
  }, [refreshCurrentData, selectedLocationId, token]);

  async function handleLogin(email: string, password: string) {
    setError(null);
    const payload = await login(email, password);

    await AsyncStorage.setItem(TOKEN_KEY, payload.token);
    setToken(payload.token);
    await loadBootstrap(payload.token);
  }

  if (isBooting) {
    return (
      <SafeAreaView style={styles.centered}>
        <StatusBar style="dark" />
        <ActivityIndicator color={colors.primary} />
        <Text style={styles.mutedText}>Starter PlateBook</Text>
      </SafeAreaView>
    );
  }

  if (!token || !boot) {
    return <LoginScreen error={error} onLogin={handleLogin} />;
  }

  return (
    <SafeAreaView edges={['top', 'left', 'right']} style={styles.app}>
      <StatusBar style="dark" />

      <View style={styles.appHeader}>
        <View>
          <Text style={styles.headerEyebrow}>{boot.tenant.name || 'PlateBook'}</Text>
          <Text style={styles.headerTitle}>{headerTitles[activeTab]}</Text>
        </View>

        <Pressable
          accessibilityLabel="Min profil"
          onPress={() => setActiveTab('more')}
          style={({ pressed }) => [
            styles.headerProfileButton,
            activeTab === 'more' && styles.headerProfileButtonActive,
            pressed && styles.pressed,
          ]}
        >
          <View style={styles.headerProfileAvatar}>
            {boot.user.profile_photo_url ? (
              <Image source={{ uri: boot.user.profile_photo_url }} style={styles.headerProfileImage} />
            ) : (
              <Text style={styles.headerProfileInitials}>{boot.user.initials}</Text>
            )}
          </View>
          <Text style={styles.headerProfileText}>Min profil</Text>
        </Pressable>
      </View>

      {error ? (
        <Pressable onPress={() => setError(null)} style={styles.errorBanner}>
          <Ionicons name="alert-circle-outline" size={18} color={colors.dangerDeep} />
          <Text style={styles.errorBannerText}>{error}</Text>
        </Pressable>
      ) : null}

      {notice ? (
        <Pressable onPress={() => setNotice(null)} style={styles.noticeBanner}>
          <Text style={styles.noticeBannerLabel}>Obs</Text>
          <Text numberOfLines={2} style={styles.noticeBannerText}>{notice}</Text>
        </Pressable>
      ) : null}

      <View style={styles.content}>
        {activeTab === 'calendar' ? (
          <CalendarScreen
            bookings={bookings}
            calendarGrid={calendarGrid}
            date={selectedDate}
            hasWorkShiftForDate={hasWorkShiftForDate}
            locations={boot.locations}
            nextBooking={nextBooking}
            nextWorkShift={nextWorkShift}
            selectedLocation={selectedLocation}
            onChangeDate={setSelectedDate}
            onChangeLocation={setSelectedLocationId}
            onRefresh={() => refreshCurrentData(true)}
            refreshing={isRefreshing}
          />
        ) : null}

        {activeTab === 'messages' ? (
          <MessagesScreen />
        ) : null}

        {activeTab === 'shifts' ? (
          <ShiftsScreen />
        ) : null}

        {activeTab === 'more' ? (
          <MoreScreen
            apiBase={appConfig.baseUrl}
            boot={boot}
            selectedLocation={selectedLocation}
            services={services}
            onSignOut={() => {
              Alert.alert('Log ud', 'Vil du logge ud af appen?', [
                { text: 'Annuller', style: 'cancel' },
                { text: 'Log ud', style: 'destructive', onPress: signOut },
              ]);
            }}
          />
        ) : null}
      </View>

      <View style={[styles.tabBar, { height: TAB_BAR_HEIGHT + insets.bottom }]}>
        {tabs.slice(0, 2).map((tab) => {
          const isActive = activeTab === tab.key;

          return (
            <Pressable
              key={tab.key}
              onPress={() => setActiveTab(tab.key)}
              style={({ pressed }) => [
                styles.tabItem,
                { paddingBottom: Math.max(insets.bottom - 10, 0), paddingTop: 8 },
                isActive && styles.tabItemActive,
                pressed && styles.pressed,
              ]}
            >
              <Ionicons name={tab.icon} size={28} color={isActive ? colors.primaryStrong : colors.muted} />
              <Text style={[styles.tabLabel, isActive && styles.tabLabelActive]}>{tab.label}</Text>
              <View style={[styles.tabUnderline, isActive && styles.tabUnderlineActive]} />
            </Pressable>
          );
        })}

        <View style={styles.tabFabSpacer} />

        {tabs.slice(2).map((tab) => {
          const isActive = activeTab === tab.key;

          return (
            <Pressable
              key={tab.key}
              onPress={() => setActiveTab(tab.key)}
              style={({ pressed }) => [
                styles.tabItem,
                { paddingBottom: Math.max(insets.bottom - 10, 0), paddingTop: 8 },
                isActive && styles.tabItemActive,
                pressed && styles.pressed,
              ]}
            >
              <Ionicons name={tab.icon} size={28} color={isActive ? colors.primaryStrong : colors.muted} />
              <Text style={[styles.tabLabel, isActive && styles.tabLabelActive]}>{tab.label}</Text>
              <View style={[styles.tabUnderline, isActive && styles.tabUnderlineActive]} />
            </Pressable>
          );
        })}

        <CreateBookingButton
          onPress={() => {
            setActiveTab('calendar');
            setIsCreateBookingOpen(true);
          }}
        />
      </View>

      <CreateBookingModal
        date={selectedDate}
        initialLocation={selectedLocation}
        locations={boot.locations}
        onClose={() => setIsCreateBookingOpen(false)}
        onLoadOptions={handleLoadBookingOptions}
        onSubmit={handleCreateBooking}
        visible={isCreateBookingOpen}
      />
    </SafeAreaView>
  );
}

function LoginScreen({ error, onLogin }: { error: string | null; onLogin: (email: string, password: string) => Promise<void> }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [localError, setLocalError] = useState<string | null>(null);

  async function submit() {
    if (!email.trim() || !password) {
      setLocalError('Indtast e-mail og adgangskode.');
      return;
    }

    setIsSubmitting(true);
    setLocalError(null);

    try {
      await onLogin(email, password);
    } catch (caught) {
      setLocalError(caught instanceof Error ? caught.message : 'Login fejlede.');
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <SafeAreaView style={styles.loginScreen}>
      <StatusBar style="dark" />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.loginKeyboard}>
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={styles.loginContent}>
          <View style={styles.loginBrandMark}>
            <Text style={styles.loginBrandText}>PlateBook</Text>
          </View>

          <View style={styles.loginCopy}>
            <Text style={styles.loginEyebrow}>Native app</Text>
            <Text style={styles.loginTitle}>Log ind</Text>
            <Text style={styles.loginDescription}>
              Brug din PlateBook-bruger for at åbne kalender og ydelser direkte i appen.
            </Text>
          </View>

          {localError || error ? (
            <View style={styles.loginError}>
              <Ionicons name="alert-circle-outline" size={18} color={colors.dangerDeep} />
              <Text style={styles.loginErrorText}>{localError ?? error}</Text>
            </View>
          ) : null}

          <View style={styles.formBlock}>
            <LabeledInput
              autoCapitalize="none"
              autoComplete="email"
              keyboardType="email-address"
              label="E-mail"
              onChangeText={setEmail}
              placeholder="navn@firma.dk"
              value={email}
            />
            <LabeledInput
              autoComplete="current-password"
              label="Adgangskode"
              onChangeText={setPassword}
              placeholder="Din adgangskode"
              secureTextEntry
              value={password}
            />

            <Pressable
              disabled={isSubmitting}
              onPress={submit}
              style={({ pressed }) => [
                styles.primaryButton,
                isSubmitting && styles.disabled,
                pressed && styles.pressed,
              ]}
            >
              {isSubmitting ? (
                <ActivityIndicator color={colors.surface} />
              ) : (
                <>
                  <Ionicons name="log-in-outline" size={20} color={colors.surface} />
                  <Text style={styles.primaryButtonText}>Log ind</Text>
                </>
              )}
            </Pressable>
          </View>

          <Text style={styles.baseUrlText}>{appConfig.baseUrl}</Text>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

function CalendarScreen({
  bookings,
  calendarGrid,
  date,
  hasWorkShiftForDate,
  locations,
  nextBooking,
  nextWorkShift,
  selectedLocation,
  refreshing,
  onChangeDate,
  onChangeLocation,
  onRefresh,
}: {
  bookings: Booking[];
  calendarGrid: CalendarGrid | null;
  date: string;
  hasWorkShiftForDate: boolean;
  locations: Location[];
  nextBooking: Booking | null;
  nextWorkShift: WorkShiftSummary | null;
  selectedLocation: Location | null;
  refreshing: boolean;
  onChangeDate: (date: string) => void;
  onChangeLocation: (id: number) => void;
  onRefresh: () => void;
}) {
  const [nowJumpRequest, setNowJumpRequest] = useState(0);
  const canShowGrid = hasWorkShiftForDate && calendarGrid !== null;
  const visibleBookings = canShowGrid ? bookings : [];
  const jumpToNow = useCallback(() => {
    if (date !== todayIso()) {
      onChangeDate(todayIso());
    }

    setNowJumpRequest((current) => current + 1);
  }, [date, onChangeDate]);

  return (
    <View style={styles.calendarScreen}>
      <View style={styles.calendarFilters}>
        <CalendarFilterBar
          date={date}
          locations={locations}
          selectedLocation={selectedLocation}
          onChangeDate={onChangeDate}
          onChangeLocation={onChangeLocation}
        />
      </View>

      <View style={styles.calendarGridArea}>
        <NextBookingCard
          booking={nextBooking}
        />
        <View style={styles.sectionHead}>
          <Text style={styles.sectionTitle}>Min dag</Text>
          <View style={styles.sectionHeadRight}>
            <Text style={styles.sectionMeta}>{visibleBookings.length} bookinger</Text>
            <JumpToNowButton onPress={jumpToNow} />
          </View>
        </View>
        {canShowGrid ? (
          <TimelineGrid
            calendarGrid={calendarGrid}
            bookings={visibleBookings}
            date={date}
            nowJumpRequest={nowJumpRequest}
            onRefresh={onRefresh}
            refreshing={refreshing}
          />
        ) : (
          <View style={styles.calendarNoShiftState}>
            <CalendarNoShiftState
              hasWorkShiftForDate={hasWorkShiftForDate}
              nextWorkShift={nextWorkShift}
            />
          </View>
        )}
      </View>
    </View>
  );
}

function CalendarNoShiftState({
  hasWorkShiftForDate,
  nextWorkShift,
}: {
  hasWorkShiftForDate: boolean;
  nextWorkShift: WorkShiftSummary | null;
}) {
  const countdown = useCountdown(nextWorkShift?.countdown_target ?? null);
  const bodyText = hasWorkShiftForDate
    ? 'Der er ingen åbningstid på den valgte dato.'
    : 'Du har ingen vagt på den valgte dato.';

  return (
    <View style={styles.calendarNoShiftFreeState}>
      <Ionicons name="calendar-clear-outline" size={42} color={colors.primaryStrong} />
      <Text style={styles.calendarNoShiftTitle}>Ingen bookinger</Text>
      <Text style={styles.calendarNoShiftText}>{bodyText}</Text>

      {!hasWorkShiftForDate ? (
        nextWorkShift && countdown ? (
          <View style={styles.nextShiftCountdownBlock}>
            <Text style={styles.nextShiftCountdownLabel}>{nextWorkShift.countdown_label}:</Text>
            <Text style={styles.nextShiftCountdownValue}>{countdown}</Text>
            <Text numberOfLines={1} style={styles.nextShiftCountdownMeta}>
              {[nextWorkShift.location?.name, formatWorkShiftDate(nextWorkShift.starts_at), nextWorkShift.time_range]
                .filter(Boolean)
                .join(' · ')}
            </Text>
          </View>
        ) : (
          <Text style={styles.nextShiftCountdownEmpty}>Ingen kommende vagter</Text>
        )
      ) : null}
    </View>
  );
}

function ServicesScreen({
  services,
  selectedLocation,
  refreshing,
  onRefresh,
}: {
  services: Service[];
  selectedLocation: Location | null;
  refreshing: boolean;
  onRefresh: () => void;
}) {
  return (
    <FlatList
      data={services}
      keyExtractor={(item) => String(item.id)}
      ListHeaderComponent={(
        <View style={styles.screenStack}>
          <View style={styles.summaryCard}>
            <Text style={styles.summaryLabel}>Afdeling</Text>
            <Text style={styles.summaryValue}>{selectedLocation?.name ?? 'Ingen afdeling'}</Text>
            <Text style={styles.summaryMeta}>{services.length} aktive ydelser</Text>
          </View>
          <View style={styles.sectionHead}>
            <Text style={styles.sectionTitle}>Ydelser</Text>
            <Text style={styles.sectionMeta}>Live fra API</Text>
          </View>
        </View>
      )}
      ListEmptyComponent={<EmptyState icon="pricetags-outline" title="Ingen ydelser" text="Der er ingen aktive ydelser på afdelingen." />}
      contentContainerStyle={styles.listContent}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}
      renderItem={({ item }) => <ServiceCard service={item} />}
    />
  );
}

function MessagesScreen() {
  return (
    <ScrollView contentContainerStyle={styles.profileContent}>
      <View style={styles.summaryCard}>
        <Text style={styles.summaryLabel}>Beskeder</Text>
        <Text style={styles.summaryValue}>Indbakke</Text>
        <Text style={styles.summaryMeta}>0 nye beskeder</Text>
      </View>
      <EmptyState icon="chatbubbles-outline" title="Ingen beskeder" text="Der er ingen nye beskeder." />
    </ScrollView>
  );
}

function ShiftsScreen() {
  return (
    <ScrollView contentContainerStyle={styles.profileContent}>
      <View style={styles.summaryCard}>
        <Text style={styles.summaryLabel}>Vagter</Text>
        <Text style={styles.summaryValue}>Mine vagter</Text>
        <Text style={styles.summaryMeta}>0 vagter i dag</Text>
      </View>
      <EmptyState icon="time-outline" title="Ingen vagter" text="Der er ingen vagter at vise." />
    </ScrollView>
  );
}

function MoreScreen({
  apiBase,
  boot,
  selectedLocation,
  services,
  onSignOut,
}: {
  apiBase: string;
  boot: BootstrapPayload;
  selectedLocation: Location | null;
  services: Service[];
  onSignOut: () => void;
}) {
  const user = boot.user;

  return (
    <ScrollView contentContainerStyle={styles.profileContent}>
      <View style={styles.profileCard}>
        <View style={styles.avatarWrap}>
          {user.profile_photo_url ? (
            <Image source={{ uri: user.profile_photo_url }} style={styles.avatarImage} />
          ) : (
            <Text style={styles.avatarText}>{user.initials}</Text>
          )}
        </View>
        <Text style={styles.profileName}>{user.name}</Text>
        <Text style={styles.profileEmail}>{user.email}</Text>
        <Text style={styles.profileRole}>{user.role_label}</Text>
      </View>

      <View style={styles.infoCard}>
        <InfoRow label="Virksomhed" value={boot.tenant.name || 'PlateBook'} />
        <InfoRow label="API" value={apiBase} />
        <InfoRow label="Afdelinger" value={String(boot.locations.length)} />
      </View>

      <View style={styles.summaryCard}>
        <Text style={styles.summaryLabel}>Afdeling</Text>
        <Text style={styles.summaryValue}>{selectedLocation?.name ?? 'Ingen afdeling'}</Text>
        <Text style={styles.summaryMeta}>{services.length} aktive ydelser</Text>
      </View>

      <View style={styles.sectionHead}>
        <Text style={styles.sectionTitle}>Ydelser</Text>
        <Text style={styles.sectionMeta}>{services.length} aktive</Text>
      </View>

      {services.length > 0 ? (
        services.map((service) => <ServiceCard key={service.id} service={service} />)
      ) : (
        <EmptyState icon="pricetags-outline" title="Ingen ydelser" text="Der er ingen aktive ydelser på afdelingen." />
      )}

      <Pressable onPress={onSignOut} style={({ pressed }) => [styles.logoutButton, pressed && styles.pressed]}>
        <Ionicons name="log-out-outline" size={20} color={colors.dangerDeep} />
        <Text style={styles.logoutButtonText}>Log ud</Text>
      </Pressable>
    </ScrollView>
  );
}

function ProfileScreen({ apiBase, boot, onSignOut }: { apiBase: string; boot: BootstrapPayload; onSignOut: () => void }) {
  const user = boot.user;

  return (
    <ScrollView contentContainerStyle={styles.profileContent}>
      <View style={styles.profileCard}>
        <View style={styles.avatarWrap}>
          {user.profile_photo_url ? (
            <Image source={{ uri: user.profile_photo_url }} style={styles.avatarImage} />
          ) : (
            <Text style={styles.avatarText}>{user.initials}</Text>
          )}
        </View>
        <Text style={styles.profileName}>{user.name}</Text>
        <Text style={styles.profileEmail}>{user.email}</Text>
        <Text style={styles.profileRole}>{user.role_label}</Text>
      </View>

      <View style={styles.infoCard}>
        <InfoRow label="Virksomhed" value={boot.tenant.name || 'PlateBook'} />
        <InfoRow label="API" value={apiBase} />
        <InfoRow label="Afdelinger" value={String(boot.locations.length)} />
      </View>

      <Pressable onPress={onSignOut} style={({ pressed }) => [styles.logoutButton, pressed && styles.pressed]}>
        <Ionicons name="log-out-outline" size={20} color={colors.dangerDeep} />
        <Text style={styles.logoutButtonText}>Log ud</Text>
      </Pressable>
    </ScrollView>
  );
}

function LabeledInput(props: React.ComponentProps<typeof TextInput> & { label: string }) {
  const { label, style, ...inputProps } = props;

  return (
    <View style={styles.inputGroup}>
      <Text style={styles.inputLabel}>{label}</Text>
      <TextInput
        placeholderTextColor={colors.placeholder}
        style={[styles.textInput, style]}
        {...inputProps}
      />
    </View>
  );
}

function CalendarFilterBar({
  date,
  locations,
  selectedLocation,
  onChangeDate,
  onChangeLocation,
}: {
  date: string;
  locations: Location[];
  selectedLocation: Location | null;
  onChangeDate: (date: string) => void;
  onChangeLocation: (id: number) => void;
}) {
  const [isLocationOpen, setIsLocationOpen] = useState(false);
  const [isDatePickerOpen, setIsDatePickerOpen] = useState(false);
  const menuHeight = (locations.length * LOCATION_DROPDOWN_ITEM_HEIGHT) + 2;
  const overlayOffset = LOCATION_DROPDOWN_GAP + menuHeight;

  return (
    <View
      pointerEvents="box-none"
      style={[
        styles.calendarFilterWrap,
        { marginBottom: -overlayOffset },
        isLocationOpen && styles.locationDropdownOpen,
      ]}
    >
      <View style={styles.calendarFilterPill}>
        <Pressable
          onPress={() => setIsLocationOpen((current) => !current)}
          style={({ pressed }) => [styles.calendarFilterSide, pressed && styles.pressed]}
        >
          <Text numberOfLines={1} style={styles.calendarFilterPrimary}>
            {selectedLocation?.name ?? 'Vælg lokation'}
          </Text>
          <Ionicons
            name={isLocationOpen ? 'chevron-up' : 'chevron-down'}
            size={17}
            color={colors.primaryStrong}
          />
        </Pressable>

        <View style={styles.calendarFilterDivider} />

        <Pressable
          onPress={() => setIsDatePickerOpen(true)}
          style={({ pressed }) => [styles.calendarFilterSide, pressed && styles.pressed]}
        >
          <Text numberOfLines={1} style={styles.calendarFilterPrimary}>
            {formatShortDate(date)}
          </Text>
          <Ionicons name="calendar-outline" size={17} color={colors.primaryStrong} />
        </Pressable>
      </View>

      {isLocationOpen ? (
        <View style={styles.locationDropdownMenu}>
          {locations.map((location) => {
            const active = selectedLocation?.id === location.id;

            return (
              <Pressable
                key={location.id}
                onPress={() => {
                  onChangeLocation(location.id);
                  setIsLocationOpen(false);
                }}
                style={({ pressed }) => [
                  styles.locationDropdownItem,
                  active && styles.locationDropdownItemActive,
                  pressed && styles.pressed,
                ]}
              >
                <Text
                  numberOfLines={1}
                  style={[styles.locationDropdownItemText, active && styles.locationDropdownItemTextActive]}
                >
                  {location.name}
                </Text>
                {active ? <Ionicons name="checkmark" size={18} color={colors.primaryStrong} /> : null}
              </Pressable>
            );
          })}
        </View>
      ) : (
        <View pointerEvents="none" style={{ height: menuHeight }} />
      )}

      <CalendarDatePicker
        onClose={() => setIsDatePickerOpen(false)}
        onSelect={(nextDate) => {
          onChangeDate(nextDate);
          setIsDatePickerOpen(false);
        }}
        value={date}
        visible={isDatePickerOpen}
      />
    </View>
  );
}

function LocationPicker({
  locations,
  selectedLocation,
  onChange,
}: {
  locations: Location[];
  selectedLocation: Location | null;
  onChange: (id: number) => void;
}) {
  const [isOpen, setIsOpen] = useState(false);
  const menuHeight = (locations.length * LOCATION_DROPDOWN_ITEM_HEIGHT) + 2;
  const overlayOffset = LOCATION_DROPDOWN_GAP + menuHeight;

  return (
    <View
      pointerEvents="box-none"
      style={[
        styles.locationDropdown,
        { marginBottom: -overlayOffset },
        isOpen && styles.locationDropdownOpen,
      ]}
    >
      <View style={styles.locationDropdownButton}>
        <View style={styles.locationDropdownStaticSlot}>
          <Text style={styles.locationDropdownLabel}>Afdeling</Text>
        </View>
        <Pressable
          onPress={() => setIsOpen((current) => !current)}
          style={({ pressed }) => [styles.locationDropdownInnerPill, pressed && styles.pressed]}
        >
          <Text numberOfLines={1} style={styles.locationDropdownValue}>
            {selectedLocation?.name ?? 'Vælg afdeling'}
          </Text>
          <Ionicons name={isOpen ? 'chevron-up' : 'chevron-down'} size={17} color={colors.primaryStrong} />
        </Pressable>
      </View>

      {isOpen ? (
        <View style={styles.locationDropdownMenu}>
          {locations.map((location) => {
            const active = selectedLocation?.id === location.id;

            return (
              <Pressable
                key={location.id}
                onPress={() => {
                  onChange(location.id);
                  setIsOpen(false);
                }}
                style={({ pressed }) => [
                  styles.locationDropdownItem,
                  active && styles.locationDropdownItemActive,
                  pressed && styles.pressed,
                ]}
              >
                <Text
                  numberOfLines={1}
                  style={[styles.locationDropdownItemText, active && styles.locationDropdownItemTextActive]}
                >
                  {location.name}
                </Text>
                {active ? <Ionicons name="checkmark" size={18} color={colors.primaryStrong} /> : null}
              </Pressable>
            );
          })}
        </View>
      ) : (
        <View pointerEvents="none" style={{ height: menuHeight }} />
      )}
    </View>
  );
}

function DateControls({ date, onChangeDate }: { date: string; onChangeDate: (date: string) => void }) {
  const [isPickerOpen, setIsPickerOpen] = useState(false);

  return (
    <>
      <View style={styles.dateCard}>
        <Pressable onPress={() => onChangeDate(shiftDate(date, -1))} style={styles.iconButton}>
          <Ionicons name="chevron-back" size={20} color={colors.ink} />
        </Pressable>
        <Pressable
          accessibilityLabel="Vælg dato"
          onPress={() => setIsPickerOpen(true)}
          style={({ pressed }) => [styles.dateCenterButton, pressed && styles.pressed]}
        >
          <Text style={styles.dateLabel}>{formatLongDate(date)}</Text>
          <Text style={styles.dateIso}>{date}</Text>
        </Pressable>
        <Pressable onPress={() => onChangeDate(shiftDate(date, 1))} style={styles.iconButton}>
          <Ionicons name="chevron-forward" size={20} color={colors.ink} />
        </Pressable>
      </View>
      <CalendarDatePicker
        onClose={() => setIsPickerOpen(false)}
        onSelect={(nextDate) => {
          onChangeDate(nextDate);
          setIsPickerOpen(false);
        }}
        value={date}
        visible={isPickerOpen}
      />
    </>
  );
}

function CalendarDatePicker({
  visible,
  value,
  onClose,
  onSelect,
}: {
  visible: boolean;
  value: string;
  onClose: () => void;
  onSelect: (date: string) => void;
}) {
  const [visibleMonth, setVisibleMonth] = useState(() => startOfMonth(parseIsoDate(value)));

  useEffect(() => {
    if (visible) {
      setVisibleMonth(startOfMonth(parseIsoDate(value)));
    }
  }, [value, visible]);

  const monthDays = useMemo(() => buildMonthDays(visibleMonth), [visibleMonth]);
  const today = todayIso();

  return (
    <Modal animationType="fade" onRequestClose={onClose} transparent visible={visible}>
      <Pressable style={styles.datePickerBackdrop} onPress={onClose}>
        <Pressable style={styles.datePickerSheet} onPress={(event) => event.stopPropagation()}>
          <View style={styles.datePickerHeader}>
            <Pressable onPress={() => setVisibleMonth(shiftMonth(visibleMonth, -1))} style={styles.datePickerNavButton}>
              <Ionicons name="chevron-back" size={20} color={colors.ink} />
            </Pressable>
            <Text style={styles.datePickerTitle}>{formatMonthTitle(visibleMonth)}</Text>
            <Pressable onPress={() => setVisibleMonth(shiftMonth(visibleMonth, 1))} style={styles.datePickerNavButton}>
              <Ionicons name="chevron-forward" size={20} color={colors.ink} />
            </Pressable>
          </View>

          <View style={styles.datePickerWeekdays}>
            {weekdayLabels.map((label) => (
              <Text key={label} style={styles.datePickerWeekday}>{label}</Text>
            ))}
          </View>

          <View style={styles.datePickerGrid}>
            {monthDays.map((day, index) => {
              if (!day) {
                return <View key={`empty-${index}`} style={styles.datePickerDay} />;
              }

              const isSelected = day === value;
              const isToday = day === today;

              return (
                <Pressable
                  key={day}
                  onPress={() => onSelect(day)}
                  style={({ pressed }) => [
                    styles.datePickerDay,
                    isToday && styles.datePickerDayToday,
                    isSelected && styles.datePickerDaySelected,
                    pressed && styles.pressed,
                  ]}
                >
                  <Text style={[styles.datePickerDayText, isSelected && styles.datePickerDayTextSelected]}>
                    {Number(day.slice(-2))}
                  </Text>
                </Pressable>
              );
            })}
          </View>

          <View style={styles.datePickerActions}>
            <Pressable onPress={() => onSelect(today)} style={({ pressed }) => [styles.datePickerTodayButton, pressed && styles.pressed]}>
              <Text style={styles.datePickerTodayText}>I dag</Text>
            </Pressable>
            <Pressable onPress={onClose} style={({ pressed }) => [styles.datePickerCloseButton, pressed && styles.pressed]}>
              <Text style={styles.datePickerCloseText}>Luk</Text>
            </Pressable>
          </View>
        </Pressable>
      </Pressable>
    </Modal>
  );
}

function NextBookingCard({
  booking,
}: {
  booking: Booking | null;
}) {
  const detail = booking
    ? `${booking.customer} · ${formatBookingDateTime(booking)}`
    : 'Ingen kommende bookinger';

  return (
    <View style={styles.nextBookingCard}>
      <Text numberOfLines={1} style={styles.nextBookingTextLabel}>Næste booking</Text>
      <Text numberOfLines={1} style={styles.nextBookingText}>{detail}</Text>
    </View>
  );
}

function JumpToNowButton({ onPress }: { onPress: () => void }) {
  return (
    <Pressable
      accessibilityLabel="Hop til nu"
      onPress={onPress}
      style={({ pressed }) => [styles.jumpToNowButton, pressed && styles.pressed]}
    >
      <Ionicons name="eye-outline" size={18} color={colors.primaryStrong} />
    </Pressable>
  );
}

function CreateBookingButton({ onPress }: { onPress: () => void }) {
  return (
    <Pressable
      accessibilityLabel="Opret booking"
      onPress={onPress}
      style={({ pressed }) => [styles.createBookingButton, pressed && styles.pressed]}
    >
      <Ionicons name="add" size={38} color={colors.surface} />
    </Pressable>
  );
}

function useCountdown(targetIso: string | null) {
  const [nowMs, setNowMs] = useState(() => Date.now());

  useEffect(() => {
    if (!targetIso) {
      return undefined;
    }

    setNowMs(Date.now());
    const timer = setInterval(() => setNowMs(Date.now()), 1000);

    return () => clearInterval(timer);
  }, [targetIso]);

  return useMemo(() => {
    if (!targetIso) {
      return null;
    }

    const targetMs = Date.parse(targetIso);

    if (!Number.isFinite(targetMs)) {
      return null;
    }

    return formatRemainingTime(targetMs - nowMs);
  }, [nowMs, targetIso]);
}

function CreateBookingModal({
  date,
  initialLocation,
  locations,
  visible,
  onClose,
  onLoadOptions,
  onSubmit,
}: {
  date: string;
  initialLocation: Location | null;
  locations: Location[];
  visible: boolean;
  onClose: () => void;
  onLoadOptions: (date: string, locationId: number) => Promise<BookingOptionsPayload>;
  onSubmit: (payload: CreateBookingPayload) => Promise<void>;
}) {
  const initialLocationId = initialLocation?.id ?? locations[0]?.id ?? null;
  const [customerName, setCustomerName] = useState('');
  const [customerEmail, setCustomerEmail] = useState('');
  const [customerPhone, setCustomerPhone] = useState('');
  const [notes, setNotes] = useState('');
  const [bookingDate, setBookingDate] = useState(date);
  const [bookingTime, setBookingTime] = useState(defaultBookingTime(date));
  const [selectedLocationId, setSelectedLocationId] = useState<number | null>(initialLocationId);
  const [options, setOptions] = useState<BookingOptionsPayload | null>(null);
  const [selectedStaffId, setSelectedStaffId] = useState<number | null>(null);
  const [selectedServiceId, setSelectedServiceId] = useState<number | null>(null);
  const [isDatePickerOpen, setIsDatePickerOpen] = useState(false);
  const [isLoadingOptions, setIsLoadingOptions] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const selectedLocation = locations.find((location) => location.id === selectedLocationId) ?? null;
  const selectedStaff = options?.staff.find((staff) => staff.id === selectedStaffId) ?? null;
  const availableServices = useMemo(() => {
    const serviceIds = selectedStaff?.service_ids ?? [];

    if (!options || serviceIds.length === 0) {
      return [];
    }

    return options.services.filter((service) => serviceIds.includes(service.id));
  }, [options, selectedStaff]);

  useEffect(() => {
    if (!visible) {
      return;
    }

    setCustomerName('');
    setCustomerEmail('');
    setCustomerPhone('');
    setNotes('');
    setBookingDate(date);
    setBookingTime(defaultBookingTime(date));
    setSelectedLocationId(initialLocationId);
    setSelectedStaffId(null);
    setSelectedServiceId(null);
    setOptions(null);
    setFormError(null);
  }, [date, initialLocationId, visible]);

  useEffect(() => {
    if (!visible || !selectedLocationId) {
      return;
    }

    let cancelled = false;
    setIsLoadingOptions(true);
    setOptions(null);
    setSelectedStaffId(null);
    setSelectedServiceId(null);
    setFormError(null);

    onLoadOptions(bookingDate, selectedLocationId)
      .then((payload) => {
        if (cancelled) {
          return;
        }

        setOptions(payload);
        setSelectedStaffId((current) => {
          if (current && payload.staff.some((staff) => staff.id === current)) {
            return current;
          }

          return payload.staff[0]?.id ?? null;
        });
      })
      .catch((caught) => {
        if (!cancelled) {
          setOptions(null);
          setSelectedStaffId(null);
          setSelectedServiceId(null);
          setFormError(caught instanceof Error ? caught.message : 'Kunne ikke hente behandlere.');
        }
      })
      .finally(() => {
        if (!cancelled) {
          setIsLoadingOptions(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [bookingDate, onLoadOptions, selectedLocationId, visible]);

  useEffect(() => {
    setSelectedServiceId((current) => {
      if (current && availableServices.some((service) => service.id === current)) {
        return current;
      }

      return availableServices[0]?.id ?? null;
    });
  }, [availableServices]);

  async function submit() {
    const normalizedTime = normalizeTimeInput(bookingTime);

    if (!selectedLocationId) {
      setFormError('Vælg en afdeling først.');
      return;
    }

    if (!selectedStaffId) {
      setFormError('Vælg en behandler.');
      return;
    }

    if (!selectedServiceId) {
      setFormError('Vælg en ydelse.');
      return;
    }

    if (!customerName.trim()) {
      setFormError('Indtast kundens navn.');
      return;
    }

    if (!normalizedTime) {
      setFormError('Tidspunkt skal skrives som fx 09:00.');
      return;
    }

    setIsSubmitting(true);
    setFormError(null);

    try {
      await onSubmit({
        location_id: selectedLocationId,
        staff_user_id: selectedStaffId,
        service_id: selectedServiceId,
        booking_date: bookingDate,
        booking_time: normalizedTime,
        customer_name: customerName.trim(),
        customer_email: customerEmail.trim() || null,
        customer_phone: customerPhone.trim() || null,
        notes: notes.trim() || null,
      });
      onClose();
    } catch (caught) {
      setFormError(caught instanceof Error ? caught.message : 'Bookingen kunne ikke oprettes.');
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <Modal animationType="slide" onRequestClose={onClose} transparent visible={visible}>
      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        style={styles.createBookingModalRoot}
      >
        <Pressable style={styles.createBookingBackdrop} onPress={onClose} />
        <View style={styles.createBookingSheet}>
          <View style={styles.createBookingHeader}>
            <View>
              <Text style={styles.createBookingEyebrow}>{selectedLocation?.name ?? 'Ingen afdeling'}</Text>
              <Text style={styles.createBookingTitle}>Opret booking</Text>
            </View>
            <Pressable
              accessibilityLabel="Luk"
              onPress={onClose}
              style={({ pressed }) => [styles.createBookingClose, pressed && styles.pressed]}
            >
              <Ionicons name="close" size={20} color={colors.ink} />
            </Pressable>
          </View>

          {formError ? (
            <View style={styles.createBookingError}>
              <Ionicons name="alert-circle-outline" size={18} color={colors.dangerDeep} />
              <Text style={styles.createBookingErrorText}>{formError}</Text>
            </View>
          ) : null}

          <ScrollView
            contentContainerStyle={styles.createBookingContent}
            keyboardShouldPersistTaps="handled"
            showsVerticalScrollIndicator={false}
          >
            {locations.length > 1 ? (
              <View style={styles.inputGroup}>
                <Text style={styles.inputLabel}>Afdeling</Text>
                <View style={styles.serviceChoiceList}>
                  {locations.map((location) => {
                    const active = selectedLocationId === location.id;

                    return (
                      <Pressable
                        key={location.id}
                        onPress={() => setSelectedLocationId(location.id)}
                        style={({ pressed }) => [
                          styles.serviceChoice,
                          active && styles.serviceChoiceActive,
                          pressed && styles.pressed,
                        ]}
                      >
                        <Ionicons name="business-outline" size={18} color={colors.primaryStrong} />
                        <View style={styles.serviceChoiceTextWrap}>
                          <Text numberOfLines={1} style={styles.serviceChoiceName}>{location.name}</Text>
                          {location.city ? (
                            <Text numberOfLines={1} style={styles.serviceChoiceMeta}>{location.city}</Text>
                          ) : null}
                        </View>
                        {active ? <Ionicons name="checkmark" size={18} color={colors.primaryStrong} /> : null}
                      </Pressable>
                    );
                  })}
                </View>
              </View>
            ) : null}

            <View style={styles.createBookingDateTimeRow}>
              <View style={styles.createBookingDateColumn}>
                <Text style={styles.inputLabel}>Dato</Text>
                <Pressable
                  onPress={() => setIsDatePickerOpen(true)}
                  style={({ pressed }) => [styles.createBookingDateButton, pressed && styles.pressed]}
                >
                  <Text numberOfLines={1} style={styles.createBookingDateText}>{formatShortDate(bookingDate)}</Text>
                  <Ionicons name="calendar-outline" size={18} color={colors.primaryStrong} />
                </Pressable>
              </View>

              <View style={styles.createBookingTimeColumn}>
                <LabeledInput
                  autoCapitalize="none"
                  label="Tid"
                  onChangeText={setBookingTime}
                  placeholder="09:00"
                  value={bookingTime}
                />
              </View>
            </View>

            <View style={styles.inputGroup}>
              <Text style={styles.inputLabel}>Behandler</Text>
              <View style={styles.serviceChoiceList}>
                {isLoadingOptions ? (
                  <View style={styles.createBookingInlineLoader}>
                    <ActivityIndicator color={colors.primaryStrong} />
                  </View>
                ) : options && options.staff.length > 0 ? (
                  options.staff.map((staff) => {
                    const active = selectedStaffId === staff.id;

                    return (
                      <Pressable
                        key={staff.id}
                        onPress={() => setSelectedStaffId(staff.id)}
                        style={({ pressed }) => [
                          styles.serviceChoice,
                          active && styles.serviceChoiceActive,
                          pressed && styles.pressed,
                        ]}
                      >
                        <View style={styles.staffChoiceAvatar}>
                          <Text style={styles.staffChoiceInitials}>{staff.initials}</Text>
                        </View>
                        <View style={styles.serviceChoiceTextWrap}>
                          <Text numberOfLines={1} style={styles.serviceChoiceName}>{staff.name}</Text>
                          <Text style={styles.serviceChoiceMeta}>{staff.service_ids?.length ?? 0} ydelser</Text>
                        </View>
                        {active ? <Ionicons name="checkmark" size={18} color={colors.primaryStrong} /> : null}
                      </Pressable>
                    );
                  })
                ) : (
                  <Text style={styles.createBookingMuted}>Ingen vagtplanerede behandlere på den valgte dato.</Text>
                )}
              </View>
            </View>

            <View style={styles.inputGroup}>
              <Text style={styles.inputLabel}>Ydelse</Text>
              <View style={styles.serviceChoiceList}>
                {availableServices.length > 0 ? (
                  availableServices.map((service) => {
                    const active = selectedServiceId === service.id;
                    const serviceColor = normalizeColor(service.color);

                    return (
                      <Pressable
                        key={service.id}
                        onPress={() => setSelectedServiceId(service.id)}
                        style={({ pressed }) => [
                          styles.serviceChoice,
                          active && styles.serviceChoiceActive,
                          active && { borderColor: serviceColor, backgroundColor: `${serviceColor}18` },
                          pressed && styles.pressed,
                        ]}
                      >
                        <View style={[styles.serviceChoiceDot, { backgroundColor: serviceColor }]} />
                        <View style={styles.serviceChoiceTextWrap}>
                          <Text numberOfLines={1} style={styles.serviceChoiceName}>{service.name}</Text>
                          <Text style={styles.serviceChoiceMeta}>{service.duration_minutes} min.</Text>
                        </View>
                        {active ? <Ionicons name="checkmark" size={18} color={colors.primaryStrong} /> : null}
                      </Pressable>
                    );
                  })
                ) : (
                  <Text style={styles.createBookingMuted}>Vælg en behandler med tilknyttede ydelser.</Text>
                )}
              </View>
            </View>

            <LabeledInput
              autoCapitalize="words"
              label="Kunde"
              onChangeText={setCustomerName}
              placeholder="Kundens navn"
              value={customerName}
            />

            <LabeledInput
              autoCapitalize="none"
              autoComplete="email"
              keyboardType="email-address"
              label="E-mail"
              onChangeText={setCustomerEmail}
              placeholder="Valgfri"
              value={customerEmail}
            />

            <LabeledInput
              keyboardType="phone-pad"
              label="Telefon"
              onChangeText={setCustomerPhone}
              placeholder="Valgfri"
              value={customerPhone}
            />

            <LabeledInput
              label="Note"
              multiline
              onChangeText={setNotes}
              placeholder="Valgfri note"
              style={styles.notesInput}
              value={notes}
            />
          </ScrollView>

          <View style={styles.createBookingFooter}>
            <Pressable
              disabled={isSubmitting || isLoadingOptions || !selectedLocationId || !selectedStaffId || !selectedServiceId}
              onPress={submit}
              style={({ pressed }) => [
                styles.primaryButton,
                (isSubmitting || isLoadingOptions || !selectedLocationId || !selectedStaffId || !selectedServiceId) && styles.disabled,
                pressed && styles.pressed,
              ]}
            >
              {isSubmitting ? (
                <ActivityIndicator color={colors.surface} />
              ) : (
                <>
                  <Ionicons name="add" size={21} color={colors.surface} />
                  <Text style={styles.primaryButtonText}>Opret booking</Text>
                </>
              )}
            </Pressable>
          </View>
        </View>

        <CalendarDatePicker
          onClose={() => setIsDatePickerOpen(false)}
          onSelect={(nextDate) => {
            setBookingDate(nextDate);
            setBookingTime(defaultBookingTime(nextDate));
            setIsDatePickerOpen(false);
          }}
          value={bookingDate}
          visible={isDatePickerOpen}
        />
      </KeyboardAvoidingView>
    </Modal>
  );
}

function TimelineGrid({
  calendarGrid,
  bookings,
  date,
  nowJumpRequest,
  refreshing,
  onRefresh,
}: {
  calendarGrid: CalendarGrid | null;
  bookings: Booking[];
  date: string;
  nowJumpRequest: number;
  refreshing: boolean;
  onRefresh: () => void;
}) {
  const scrollRef = useRef<ScrollView>(null);
  const bounds = useMemo(() => resolveTimelineBounds(bookings, date, calendarGrid), [bookings, calendarGrid, date]);
  const slots = useMemo(() => buildTimelineSlots(bounds.startMinutes, bounds.endMinutes), [bounds]);
  const gridHeight = slots.length * SLOT_HEIGHT;
  const nowPosition = resolveNowPosition(date, bounds.startMinutes, bounds.endMinutes);
  const openingIntervals = calendarGrid?.opening_intervals ?? [];
  const workShiftIntervals = calendarGrid?.work_shift_intervals ?? [];

  useEffect(() => {
    if (nowJumpRequest <= 0 || date !== todayIso()) {
      return;
    }

    const offset = resolveNowScrollOffset(date, bounds.startMinutes, bounds.endMinutes);

    if (offset === null) {
      return;
    }

    requestAnimationFrame(() => {
      scrollRef.current?.scrollTo({ y: offset, animated: true });
    });
  }, [bounds.endMinutes, bounds.startMinutes, date, nowJumpRequest]);

  return (
    <View style={styles.timelineShell}>
      <ScrollView
        ref={scrollRef}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}
        showsVerticalScrollIndicator
        style={styles.timelineScroll}
      >
        <View style={[styles.timelineGrid, { height: gridHeight }]}>
          {slots.map((minutes, index) => {
            const isHour = minutes % 60 === 0;

            return (
              <View
                key={minutes}
                pointerEvents="none"
                style={[styles.timelineRow, { top: index * SLOT_HEIGHT, height: SLOT_HEIGHT }]}
              >
                <Text style={[styles.timelineTime, !isHour && styles.timelineTimeMuted]}>
                  {isHour ? formatClock(minutes) : ''}
                </Text>
                <View style={[styles.timelineLine, isHour && styles.timelineLineHour]} />
              </View>
            );
          })}

          {slots.map((minutes, index) => {
            const slotEnd = minutes + SLOT_MINUTES;
            const isAvailable = intervalContains(openingIntervals, minutes, slotEnd)
              && intervalContains(workShiftIntervals, minutes, slotEnd);

            if (isAvailable) {
              return null;
            }

            return (
              <View
                key={`unavailable-${minutes}`}
                pointerEvents="none"
                style={[styles.timelineUnavailableSlot, { top: index * SLOT_HEIGHT, height: SLOT_HEIGHT }]}
              />
            );
          })}

          {nowPosition !== null ? (
            <View pointerEvents="none" style={[styles.timelineNowLine, { top: nowPosition }]}>
              <View style={styles.timelineNowDot} />
              <Text style={styles.timelineNowLabel}>Nu</Text>
            </View>
          ) : null}

          {bookings.map((booking) => {
            const position = bookingPosition(booking, bounds.startMinutes);

            if (!position) {
              return null;
            }

            const color = normalizeColor(booking.service_color);

            return (
              <Pressable
                key={booking.id}
                style={({ pressed }) => [
                  styles.timelineBooking,
                  {
                    top: position.top,
                    height: position.height,
                    borderColor: color,
                    backgroundColor: `${color}20`,
                    shadowColor: color,
                  },
                  pressed && styles.pressed,
                ]}
              >
                <View style={[styles.timelineBookingStripe, { backgroundColor: color }]} />
                <View style={styles.timelineBookingBody}>
                  <Text numberOfLines={1} style={styles.timelineBookingTitle}>{booking.customer}</Text>
                  <Text numberOfLines={1} style={styles.timelineBookingService}>{booking.service}</Text>
                  <View style={styles.timelineBookingMetaRow}>
                    <Text numberOfLines={1} style={styles.timelineBookingTime}>{booking.time_range}</Text>
                    <StatusPill status={booking.status} />
                  </View>
                </View>
              </Pressable>
            );
          })}

        </View>
      </ScrollView>
    </View>
  );
}

function BookingCard({ booking }: { booking: Booking }) {
  return (
    <View style={styles.bookingCard}>
      <View style={[styles.serviceStripe, { backgroundColor: normalizeColor(booking.service_color) }]} />
      <View style={styles.cardBody}>
        <View style={styles.cardTopLine}>
          <Text style={styles.cardTime}>{booking.time_range}</Text>
          <StatusPill status={booking.status} />
        </View>
        <Text style={styles.cardTitle}>{booking.customer}</Text>
        <Text style={styles.cardSubTitle}>{booking.service}</Text>
        <View style={styles.metaRow}>
          <Ionicons name="person-outline" size={14} color={colors.muted} />
          <Text style={styles.metaText}>{booking.staff_name}</Text>
        </View>
        {booking.customer_phone ? (
          <View style={styles.metaRow}>
            <Ionicons name="call-outline" size={14} color={colors.muted} />
            <Text style={styles.metaText}>{booking.customer_phone}</Text>
          </View>
        ) : null}
      </View>
    </View>
  );
}

function ServiceCard({ service }: { service: Service }) {
  return (
    <View style={styles.serviceCard}>
      <View style={[styles.serviceDot, { backgroundColor: normalizeColor(service.color) }]} />
      <View style={styles.serviceBody}>
        <Text style={styles.cardTitle}>{service.name}</Text>
        <Text style={styles.cardSubTitle}>{service.category}</Text>
        <View style={styles.serviceMeta}>
          <Text style={styles.serviceMetaText}>{service.duration_minutes} min</Text>
          <Text style={styles.serviceMetaText}>{formatPrice(service.price_minor)}</Text>
          <Text style={styles.serviceMetaText}>{service.online_bookable ? 'Online' : 'Intern'}</Text>
        </View>
      </View>
    </View>
  );
}

function EmptyState({ icon, title, text }: { icon: IconName; title: string; text: string }) {
  return (
    <View style={styles.emptyState}>
      <Ionicons name={icon} size={34} color={colors.muted} />
      <Text style={styles.emptyTitle}>{title}</Text>
      <Text style={styles.emptyText}>{text}</Text>
    </View>
  );
}

function InfoRow({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.infoRow}>
      <Text style={styles.infoLabel}>{label}</Text>
      <Text numberOfLines={2} style={styles.infoValue}>{value}</Text>
    </View>
  );
}

function StatusPill({ status }: { status: string }) {
  const label = status === 'completed' ? 'Gennemført' : status === 'canceled' ? 'Annulleret' : 'Bekræftet';

  return (
    <View style={styles.statusPill}>
      <Text style={styles.statusPillText}>{label}</Text>
    </View>
  );
}

function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

function shiftDate(value: string, days: number) {
  const date = new Date(`${value}T12:00:00`);
  date.setDate(date.getDate() + days);

  return date.toISOString().slice(0, 10);
}

function parseIsoDate(value: string) {
  const [year, month, day] = value.split('-').map(Number);

  return new Date(year, month - 1, day, 12);
}

function startOfMonth(value: Date) {
  return new Date(value.getFullYear(), value.getMonth(), 1, 12);
}

function shiftMonth(value: Date, months: number) {
  return new Date(value.getFullYear(), value.getMonth() + months, 1, 12);
}

function formatIsoDate(value: Date) {
  return [
    value.getFullYear(),
    String(value.getMonth() + 1).padStart(2, '0'),
    String(value.getDate()).padStart(2, '0'),
  ].join('-');
}

function buildMonthDays(monthDate: Date) {
  const year = monthDate.getFullYear();
  const month = monthDate.getMonth();
  const firstDay = new Date(year, month, 1, 12);
  const firstWeekday = (firstDay.getDay() + 6) % 7;
  const daysInMonth = new Date(year, month + 1, 0, 12).getDate();
  const days: Array<string | null> = Array.from({ length: firstWeekday }, () => null);

  for (let day = 1; day <= daysInMonth; day += 1) {
    days.push(formatIsoDate(new Date(year, month, day, 12)));
  }

  while (days.length % 7 !== 0) {
    days.push(null);
  }

  return days;
}

function resolveTimelineBounds(bookings: Booking[], date: string, calendarGrid: CalendarGrid | null) {
  if (calendarGrid && calendarGrid.end_minutes > calendarGrid.start_minutes) {
    return {
      startMinutes: normalizeSlotMinute(calendarGrid.start_minutes, 'floor'),
      endMinutes: normalizeSlotMinute(calendarGrid.end_minutes, 'ceil'),
    };
  }

  const defaultStart = 8 * 60;
  const defaultEnd = 18 * 60;
  const ranges = bookings
    .map(parseBookingRange)
    .filter((range): range is { start: number; end: number } => range !== null);

  if (date === todayIso()) {
    const now = new Date();
    const nowMinutes = (now.getHours() * 60) + now.getMinutes();
    ranges.push({ start: nowMinutes, end: nowMinutes + SLOT_MINUTES });
  }

  if (ranges.length === 0) {
    return {
      startMinutes: defaultStart,
      endMinutes: defaultEnd,
    };
  }

  const minStart = Math.min(...ranges.map((range) => range.start));
  const maxEnd = Math.max(...ranges.map((range) => range.end));
  const startMinutes = Math.max(0, Math.min(defaultStart, Math.floor((minStart - 60) / 60) * 60));
  const endMinutes = Math.min(24 * 60, Math.max(defaultEnd, Math.ceil((maxEnd + 60) / 60) * 60));

  return {
    startMinutes,
    endMinutes,
  };
}

function normalizeSlotMinute(minutes: number, mode: 'floor' | 'ceil') {
  const bounded = Math.max(0, Math.min(24 * 60, minutes));

  if (mode === 'floor') {
    return Math.floor(bounded / SLOT_MINUTES) * SLOT_MINUTES;
  }

  return Math.ceil(bounded / SLOT_MINUTES) * SLOT_MINUTES;
}

function intervalContains(intervals: CalendarInterval[], startMinutes: number, endMinutes: number) {
  return intervals.some((interval) => startMinutes >= interval.start_minutes && endMinutes <= interval.end_minutes);
}

function buildTimelineSlots(startMinutes: number, endMinutes: number) {
  const slots: number[] = [];

  for (let minutes = startMinutes; minutes < endMinutes; minutes += SLOT_MINUTES) {
    slots.push(minutes);
  }

  return slots;
}

function resolveNowPosition(date: string, gridStartMinutes: number, gridEndMinutes: number) {
  if (date !== todayIso()) {
    return null;
  }

  const now = new Date();
  const nowMinutes = (now.getHours() * 60) + now.getMinutes();

  if (nowMinutes < gridStartMinutes || nowMinutes > gridEndMinutes) {
    return null;
  }

  return ((nowMinutes - gridStartMinutes) / SLOT_MINUTES) * SLOT_HEIGHT;
}

function resolveNowScrollOffset(date: string, gridStartMinutes: number, gridEndMinutes: number) {
  const nowPosition = resolveNowPosition(date, gridStartMinutes, gridEndMinutes);

  if (nowPosition === null) {
    return null;
  }

  return Math.max(0, nowPosition - (SLOT_HEIGHT * 3));
}

function bookingPosition(booking: Booking, gridStartMinutes: number) {
  const range = parseBookingRange(booking);

  if (!range) {
    return null;
  }

  return {
    top: ((range.start - gridStartMinutes) / SLOT_MINUTES) * SLOT_HEIGHT,
    height: Math.max(SLOT_HEIGHT * 2, ((range.end - range.start) / SLOT_MINUTES) * SLOT_HEIGHT),
  };
}

function parseBookingRange(booking: Booking) {
  const match = /^(\d{2}):(\d{2})\s*-\s*(\d{2}):(\d{2})$/.exec(booking.time_range);

  if (!match) {
    return null;
  }

  const start = Number(match[1]) * 60 + Number(match[2]);
  const end = Number(match[3]) * 60 + Number(match[4]);

  if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) {
    return null;
  }

  return { start, end };
}

function formatClock(minutes: number) {
  const hours = Math.floor(minutes / 60);
  const mins = minutes % 60;

  return `${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}`;
}

function formatLongDate(value: string) {
  return new Intl.DateTimeFormat('da-DK', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  }).format(new Date(`${value}T12:00:00`));
}

function formatShortDate(value: string) {
  return new Intl.DateTimeFormat('da-DK', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
  }).format(new Date(`${value}T12:00:00`));
}

function formatBookingDateTime(booking: Booking) {
  const date = booking.starts_at.slice(0, 10);
  const formattedDate = /^\d{4}-\d{2}-\d{2}$/.test(date) ? formatShortDate(date) : 'Dato ukendt';

  return `${formattedDate} · ${booking.time_range}`;
}

function formatWorkShiftDate(value: string) {
  const date = value.slice(0, 10);

  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
    return '';
  }

  return formatShortDate(date);
}

function formatRemainingTime(diffMs: number) {
  if (diffMs <= 0) {
    return 'Nu';
  }

  const totalSeconds = Math.floor(diffMs / 1000);
  const days = Math.floor(totalSeconds / 86400);
  const hours = Math.floor((totalSeconds % 86400) / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;
  const hh = String(hours).padStart(2, '0');
  const mm = String(minutes).padStart(2, '0');
  const ss = String(seconds).padStart(2, '0');

  if (days > 0) {
    return `${days} d ${hh}:${mm}:${ss}`;
  }

  return `${hh}:${mm}:${ss}`;
}

function formatMonthTitle(value: Date) {
  return new Intl.DateTimeFormat('da-DK', {
    month: 'long',
    year: 'numeric',
  }).format(value);
}

function formatPrice(priceMinor?: number | null) {
  if (priceMinor === null || priceMinor === undefined) {
    return 'Ingen pris';
  }

  return `${new Intl.NumberFormat('da-DK').format(priceMinor / 100)} kr.`;
}

function defaultBookingTime(date: string) {
  if (date !== todayIso()) {
    return '09:00';
  }

  const now = new Date();
  const minutes = (now.getHours() * 60) + now.getMinutes();
  const nextQuarter = Math.min(23 * 60 + 45, Math.ceil((minutes + 1) / 15) * 15);

  return formatClock(nextQuarter);
}

function normalizeTimeInput(value: string) {
  const trimmed = value.trim();
  const match = /^(\d{1,2}):?(\d{2})$/.exec(trimmed);

  if (!match) {
    return null;
  }

  const hours = Number(match[1]);
  const minutes = Number(match[2]);

  if (hours < 0 || hours > 23 || ![0, 15, 30, 45].includes(minutes)) {
    return null;
  }

  return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
}

function normalizeColor(value: string) {
  return /^#[0-9a-f]{6}$/i.test(value) ? value : colors.primary;
}

const styles = StyleSheet.create({
  app: {
    flex: 1,
    backgroundColor: colors.surface,
  },
  centered: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 12,
    backgroundColor: colors.bg,
  },
  mutedText: {
    fontSize: 14,
    color: colors.muted,
  },
  appHeader: {
    minHeight: 68,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
    paddingHorizontal: 18,
    borderBottomColor: colors.border,
    borderBottomWidth: StyleSheet.hairlineWidth,
    backgroundColor: colors.surface,
  },
  headerEyebrow: {
    fontSize: 12,
    fontWeight: '700',
    color: colors.muted,
  },
  headerTitle: {
    marginTop: 2,
    fontSize: 22,
    fontWeight: '800',
    color: colors.primary,
  },
  iconButton: {
    width: 44,
    height: 44,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 999,
    backgroundColor: colors.surface,
  },
  headerProfileButton: {
    minHeight: 40,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    paddingLeft: 5,
    paddingRight: 12,
    borderRadius: 999,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surfaceAlt,
  },
  headerProfileButtonActive: {
    borderColor: colors.primary,
    backgroundColor: colors.primarySoft,
  },
  headerProfileAvatar: {
    width: 30,
    height: 30,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 15,
    backgroundColor: colors.headerSurface,
    overflow: 'hidden',
  },
  headerProfileImage: {
    width: 30,
    height: 30,
  },
  headerProfileInitials: {
    fontSize: 11,
    fontWeight: '900',
    color: colors.primaryStrong,
  },
  headerProfileText: {
    fontSize: 13,
    fontWeight: '900',
    color: colors.primaryStrong,
  },
  content: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  calendarScreen: {
    flex: 1,
    padding: 16,
    paddingBottom: 16,
  },
  calendarFilters: {
    gap: 12,
    zIndex: 20,
  },
  calendarGridArea: {
    flex: 1,
    gap: 12,
    minHeight: 0,
    marginTop: 12,
  },
  calendarNoShiftState: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    paddingHorizontal: 18,
  },
  calendarNoShiftFreeState: {
    width: '100%',
    alignItems: 'center',
    gap: 10,
  },
  calendarNoShiftTitle: {
    marginTop: 2,
    fontSize: 30,
    fontWeight: '900',
    color: colors.ink,
    textAlign: 'center',
  },
  calendarNoShiftText: {
    maxWidth: 280,
    fontSize: 16,
    lineHeight: 23,
    fontWeight: '600',
    color: colors.muted,
    textAlign: 'center',
  },
  nextShiftCountdownBlock: {
    alignItems: 'center',
    gap: 4,
    marginTop: 12,
  },
  nextShiftCountdownLabel: {
    fontSize: 13,
    fontWeight: '800',
    color: colors.primaryStrong,
  },
  nextShiftCountdownValue: {
    fontSize: 28,
    fontWeight: '900',
    color: colors.ink,
    letterSpacing: 0,
  },
  nextShiftCountdownMeta: {
    maxWidth: 300,
    fontSize: 12,
    fontWeight: '700',
    color: colors.muted,
    textAlign: 'center',
  },
  nextShiftCountdownEmpty: {
    marginTop: 12,
    fontSize: 14,
    fontWeight: '800',
    color: colors.muted,
    textAlign: 'center',
  },
  listContent: {
    padding: 16,
    paddingBottom: 28,
  },
  screenStack: {
    gap: 12,
    marginBottom: 12,
  },
  calendarFilterWrap: {
    gap: LOCATION_DROPDOWN_GAP,
    zIndex: 1,
  },
  calendarFilterPill: {
    minHeight: 46,
    flexDirection: 'row',
    alignItems: 'center',
    padding: 5,
    borderRadius: 999,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  calendarFilterSide: {
    flex: 1,
    minHeight: 34,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 7,
    paddingHorizontal: 10,
    borderRadius: 999,
  },
  calendarFilterPrimary: {
    flexShrink: 1,
    textAlign: 'center',
    fontSize: 14,
    fontWeight: '900',
    color: colors.primaryStrong,
    textTransform: 'capitalize',
  },
  calendarFilterDivider: {
    width: 1,
    height: 24,
    backgroundColor: colors.border,
  },
  locationDropdown: {
    gap: LOCATION_DROPDOWN_GAP,
    zIndex: 1,
  },
  locationDropdownOpen: {
    zIndex: 30,
    elevation: 30,
  },
  locationDropdownButton: {
    minHeight: 46,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 4,
    padding: 5,
    paddingLeft: 5,
    paddingRight: 5,
    borderRadius: 999,
    borderWidth: 1,
    borderColor: colors.primaryStrong,
    backgroundColor: colors.primary,
  },
  locationDropdownStaticSlot: {
    flex: 1,
    minHeight: 34,
    alignItems: 'center',
    justifyContent: 'center',
  },
  locationDropdownInnerPill: {
    flex: 1,
    minHeight: 34,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    paddingHorizontal: 14,
    borderRadius: 999,
    backgroundColor: colors.surface,
  },
  locationDropdownLabel: {
    fontSize: 13,
    fontWeight: '600',
    color: colors.primarySoft,
    textAlign: 'center',
  },
  locationDropdownValue: {
    flexShrink: 1,
    fontSize: 14,
    fontWeight: '900',
    color: colors.primaryStrong,
    textAlign: 'center',
  },
  locationDropdownMenu: {
    zIndex: 40,
    overflow: 'hidden',
    borderRadius: 14,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
    shadowColor: colors.ink,
    shadowOffset: { width: 0, height: 10 },
    shadowOpacity: 0.12,
    shadowRadius: 18,
    elevation: 18,
  },
  locationDropdownItem: {
    height: LOCATION_DROPDOWN_ITEM_HEIGHT,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 10,
    paddingHorizontal: 14,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  locationDropdownItemActive: {
    backgroundColor: colors.primarySoft,
  },
  locationDropdownItemText: {
    flex: 1,
    fontSize: 14,
    fontWeight: '800',
    color: colors.text,
  },
  locationDropdownItemTextActive: {
    color: colors.primaryStrong,
  },
  dateCard: {
    minHeight: 50,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    padding: 5,
    borderRadius: 999,
    backgroundColor: colors.surfaceAlt,
    borderWidth: 1,
    borderColor: colors.border,
  },
  dateCenter: {
    flex: 1,
    alignItems: 'center',
  },
  dateCenterButton: {
    flex: 1,
    minHeight: 40,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 999,
  },
  dateLabel: {
    fontSize: 15,
    fontWeight: '800',
    color: colors.primaryStrong,
    textTransform: 'capitalize',
  },
  dateIso: {
    marginTop: 2,
    fontSize: 12,
    color: colors.muted,
  },
  datePickerBackdrop: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 18,
    backgroundColor: 'rgba(14, 24, 51, 0.28)',
  },
  datePickerSheet: {
    width: '100%',
    maxWidth: 390,
    padding: 14,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
    shadowColor: colors.ink,
    shadowOffset: { width: 0, height: 18 },
    shadowOpacity: 0.18,
    shadowRadius: 28,
    elevation: 24,
  },
  datePickerHeader: {
    minHeight: 44,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 10,
    marginBottom: 10,
  },
  datePickerNavButton: {
    width: 40,
    height: 40,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 999,
    backgroundColor: colors.surfaceAlt,
  },
  datePickerTitle: {
    flex: 1,
    textAlign: 'center',
    textTransform: 'capitalize',
    fontSize: 17,
    fontWeight: '900',
    color: colors.primaryStrong,
  },
  datePickerWeekdays: {
    flexDirection: 'row',
    marginBottom: 6,
  },
  datePickerWeekday: {
    width: '14.2857%',
    textAlign: 'center',
    fontSize: 11,
    fontWeight: '900',
    color: colors.muted,
  },
  datePickerGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
  },
  datePickerDay: {
    width: '14.2857%',
    aspectRatio: 1,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 999,
    borderWidth: 1,
    borderColor: 'transparent',
  },
  datePickerDayToday: {
    borderColor: colors.primary,
  },
  datePickerDaySelected: {
    backgroundColor: colors.primary,
    borderColor: colors.primary,
  },
  datePickerDayText: {
    fontSize: 14,
    fontWeight: '800',
    color: colors.text,
  },
  datePickerDayTextSelected: {
    color: colors.surface,
  },
  datePickerActions: {
    flexDirection: 'row',
    justifyContent: 'flex-end',
    gap: 8,
    marginTop: 12,
  },
  datePickerTodayButton: {
    minHeight: 38,
    justifyContent: 'center',
    paddingHorizontal: 14,
    borderRadius: 999,
    backgroundColor: colors.primarySoft,
  },
  datePickerTodayText: {
    fontSize: 13,
    fontWeight: '900',
    color: colors.primaryStrong,
  },
  datePickerCloseButton: {
    minHeight: 38,
    justifyContent: 'center',
    paddingHorizontal: 14,
    borderRadius: 999,
    backgroundColor: colors.surfaceAlt,
  },
  datePickerCloseText: {
    fontSize: 13,
    fontWeight: '900',
    color: colors.text,
  },
  createBookingModalRoot: {
    flex: 1,
    justifyContent: 'flex-end',
  },
  createBookingBackdrop: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: 'rgba(14, 24, 51, 0.32)',
  },
  createBookingSheet: {
    maxHeight: '88%',
    borderTopLeftRadius: 18,
    borderTopRightRadius: 18,
    backgroundColor: colors.surface,
    shadowColor: colors.ink,
    shadowOffset: { width: 0, height: -12 },
    shadowOpacity: 0.16,
    shadowRadius: 24,
    elevation: 24,
  },
  createBookingHeader: {
    minHeight: 70,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
    paddingHorizontal: 18,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  createBookingEyebrow: {
    fontSize: 12,
    fontWeight: '800',
    color: colors.muted,
  },
  createBookingTitle: {
    marginTop: 2,
    fontSize: 20,
    fontWeight: '900',
    color: colors.primaryStrong,
  },
  createBookingClose: {
    width: 40,
    height: 40,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 999,
    backgroundColor: colors.surfaceAlt,
  },
  createBookingError: {
    minHeight: 42,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    marginHorizontal: 18,
    marginTop: 12,
    padding: 10,
    borderRadius: 8,
    backgroundColor: colors.dangerSoft,
  },
  createBookingErrorText: {
    flex: 1,
    fontSize: 13,
    fontWeight: '700',
    color: colors.dangerDeep,
  },
  createBookingContent: {
    gap: 14,
    padding: 18,
    paddingBottom: 12,
  },
  createBookingMuted: {
    fontSize: 13,
    color: colors.muted,
  },
  createBookingInlineLoader: {
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
  },
  serviceChoiceList: {
    gap: 8,
  },
  serviceChoice: {
    minHeight: 50,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    paddingHorizontal: 12,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  serviceChoiceActive: {
    borderColor: colors.primary,
    backgroundColor: colors.primarySoft,
  },
  serviceChoiceDot: {
    width: 10,
    height: 10,
    borderRadius: 5,
  },
  staffChoiceAvatar: {
    width: 30,
    height: 30,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 15,
    backgroundColor: colors.primarySoft,
  },
  staffChoiceInitials: {
    fontSize: 11,
    fontWeight: '900',
    color: colors.primaryStrong,
  },
  serviceChoiceTextWrap: {
    flex: 1,
    minWidth: 0,
  },
  serviceChoiceName: {
    fontSize: 14,
    fontWeight: '900',
    color: colors.ink,
  },
  serviceChoiceMeta: {
    marginTop: 2,
    fontSize: 12,
    fontWeight: '700',
    color: colors.muted,
  },
  createBookingDateTimeRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 10,
  },
  createBookingDateColumn: {
    flex: 1,
    minWidth: 0,
    gap: 7,
  },
  createBookingTimeColumn: {
    width: 116,
  },
  createBookingDateButton: {
    minHeight: 52,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 8,
    paddingHorizontal: 14,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  createBookingDateText: {
    flex: 1,
    fontSize: 15,
    fontWeight: '800',
    color: colors.ink,
    textTransform: 'capitalize',
  },
  notesInput: {
    minHeight: 84,
    paddingTop: 14,
    textAlignVertical: 'top',
  },
  createBookingFooter: {
    padding: 18,
    paddingTop: 10,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.border,
    backgroundColor: colors.surface,
  },
  nextBookingCard: {
    minHeight: 56,
    justifyContent: 'center',
    gap: 2,
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  nextBookingText: {
    fontSize: 13,
    fontWeight: '400',
    color: colors.text,
  },
  nextBookingTextLabel: {
    fontSize: 12,
    fontWeight: '800',
    color: colors.primaryStrong,
  },
  jumpToNowButton: {
    width: 38,
    height: 38,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 999,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  createBookingButton: {
    position: 'absolute',
    top: -12,
    left: '50%',
    marginLeft: -37,
    width: 74,
    height: 74,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 999,
    backgroundColor: colors.primaryStrong,
    shadowColor: colors.ink,
    shadowOffset: { width: 0, height: 9 },
    shadowOpacity: 0.18,
    shadowRadius: 16,
    elevation: 16,
  },
  timelineShell: {
    flex: 1,
    minHeight: 0,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: colors.gridLine,
    backgroundColor: colors.surface,
    overflow: 'hidden',
  },
  timelineGrid: {
    position: 'relative',
    minHeight: 1,
    backgroundColor: colors.surface,
  },
  timelineScroll: {
    flex: 1,
  },
  timelineRow: {
    position: 'absolute',
    left: 0,
    right: 0,
    flexDirection: 'row',
    alignItems: 'stretch',
  },
  timelineTime: {
    width: TIME_LABEL_WIDTH,
    paddingTop: 3,
    borderRightWidth: 1,
    borderRightColor: colors.gridLine,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.gridLine,
    backgroundColor: colors.gridTime,
    textAlign: 'center',
    fontSize: 9,
    fontWeight: '900',
    color: colors.ink,
  },
  timelineTimeMuted: {
    color: 'transparent',
  },
  timelineLine: {
    flex: 1,
    height: '100%',
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.gridLine,
    backgroundColor: colors.surface,
  },
  timelineLineHour: {
    borderBottomColor: colors.gridLine,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.border,
  },
  timelineUnavailableSlot: {
    position: 'absolute',
    left: TIME_LABEL_WIDTH,
    right: 0,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: 'rgba(185, 28, 28, 0.24)',
    backgroundColor: 'rgba(220, 38, 38, 0.09)',
  },
  timelineBooking: {
    position: 'absolute',
    left: TIME_LABEL_WIDTH + 4,
    right: 4,
    flexDirection: 'row',
    overflow: 'hidden',
    borderWidth: 1,
    borderRadius: 8,
    marginTop: 1,
    marginBottom: 2,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.14,
    shadowRadius: 6,
    elevation: 2,
  },
  timelineBookingStripe: {
    width: 5,
  },
  timelineBookingBody: {
    flex: 1,
    gap: 3,
    paddingHorizontal: 8,
    paddingVertical: 6,
  },
  timelineBookingTime: {
    flexShrink: 1,
    fontSize: 10,
    fontWeight: '800',
    color: colors.muted,
  },
  timelineBookingTitle: {
    fontSize: 12,
    fontWeight: '900',
    color: colors.ink,
  },
  timelineBookingService: {
    fontSize: 11,
    fontWeight: '700',
    color: colors.muted,
  },
  timelineBookingMetaRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    maxWidth: '100%',
  },
  timelineNowLine: {
    position: 'absolute',
    left: TIME_LABEL_WIDTH,
    right: 0,
    zIndex: 7,
    height: 2,
    borderRadius: 999,
    backgroundColor: colors.now,
    transform: [{ translateY: -1 }],
  },
  timelineNowDot: {
    position: 'absolute',
    left: -4,
    top: -3,
    width: 8,
    height: 8,
    borderRadius: 999,
    backgroundColor: colors.now,
    borderWidth: 1,
    borderColor: colors.surface,
  },
  timelineNowLabel: {
    position: 'absolute',
    right: 4,
    top: -18,
    minHeight: 16,
    paddingHorizontal: 5,
    borderRadius: 999,
    overflow: 'hidden',
    backgroundColor: colors.now,
    color: colors.surface,
    fontSize: 9,
    fontWeight: '900',
    letterSpacing: 0,
  },
  timelineEmptyOverlay: {
    position: 'absolute',
    left: TIME_LABEL_WIDTH + 6,
    right: 6,
    top: 58,
  },
  sectionHead: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  sectionHeadRight: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  sectionTitle: {
    fontSize: 18,
    fontWeight: '800',
    color: colors.ink,
  },
  sectionMeta: {
    fontSize: 13,
    fontWeight: '700',
    color: colors.muted,
  },
  bookingCard: {
    minHeight: 132,
    flexDirection: 'row',
    marginBottom: 10,
    borderRadius: 8,
    overflow: 'hidden',
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  serviceStripe: {
    width: 5,
  },
  cardBody: {
    flex: 1,
    padding: 14,
    gap: 5,
  },
  cardTopLine: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 8,
  },
  cardTime: {
    fontSize: 13,
    fontWeight: '800',
    color: colors.primary,
  },
  cardTitle: {
    fontSize: 17,
    fontWeight: '800',
    color: colors.ink,
  },
  cardSubTitle: {
    fontSize: 14,
    color: colors.text,
  },
  metaRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
  },
  metaText: {
    fontSize: 13,
    color: colors.muted,
  },
  statusPill: {
    minHeight: 15,
    justifyContent: 'center',
    paddingHorizontal: 5,
    borderRadius: 999,
    backgroundColor: colors.successSoft,
  },
  statusPillText: {
    fontSize: 9,
    fontWeight: '900',
    color: colors.primary,
  },
  serviceCard: {
    minHeight: 100,
    flexDirection: 'row',
    gap: 12,
    alignItems: 'center',
    marginBottom: 10,
    padding: 14,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  serviceDot: {
    width: 16,
    height: 16,
    borderRadius: 8,
  },
  serviceBody: {
    flex: 1,
    gap: 4,
  },
  serviceMeta: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
    marginTop: 5,
  },
  serviceMetaText: {
    paddingHorizontal: 8,
    paddingVertical: 4,
    borderRadius: 7,
    backgroundColor: colors.surfaceAlt,
    fontSize: 12,
    fontWeight: '700',
    color: colors.text,
  },
  summaryCard: {
    padding: 16,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  summaryLabel: {
    fontSize: 12,
    fontWeight: '800',
    color: colors.muted,
    textTransform: 'uppercase',
  },
  summaryValue: {
    marginTop: 4,
    fontSize: 21,
    fontWeight: '800',
    color: colors.ink,
  },
  summaryMeta: {
    marginTop: 2,
    fontSize: 13,
    color: colors.muted,
  },
  emptyState: {
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    padding: 34,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  emptyTitle: {
    fontSize: 17,
    fontWeight: '800',
    color: colors.ink,
  },
  emptyText: {
    fontSize: 14,
    lineHeight: 20,
    color: colors.muted,
    textAlign: 'center',
  },
  tabBar: {
    position: 'relative',
    minHeight: TAB_BAR_HEIGHT,
    flexDirection: 'row',
    borderTopColor: colors.border,
    borderTopWidth: StyleSheet.hairlineWidth,
    backgroundColor: colors.surface,
    overflow: 'visible',
  },
  tabItem: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 1,
  },
  tabItemActive: {
    backgroundColor: 'transparent',
  },
  tabFabSpacer: {
    width: 72,
  },
  tabLabel: {
    fontSize: 9,
    fontWeight: '800',
    color: colors.muted,
  },
  tabLabelActive: {
    color: colors.primaryStrong,
  },
  tabUnderline: {
    width: 30,
    height: 4,
    borderRadius: 3,
    backgroundColor: 'transparent',
  },
  tabUnderlineActive: {
    backgroundColor: colors.primaryStrong,
  },
  errorBanner: {
    minHeight: 42,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    paddingHorizontal: 16,
    backgroundColor: colors.dangerSoft,
  },
  errorBannerText: {
    flex: 1,
    fontSize: 13,
    fontWeight: '700',
    color: colors.dangerDeep,
  },
  noticeBanner: {
    minHeight: 42,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    paddingHorizontal: 16,
    paddingVertical: 8,
    borderBottomColor: colors.border,
    borderBottomWidth: StyleSheet.hairlineWidth,
    backgroundColor: colors.primarySoft,
  },
  noticeBannerLabel: {
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 999,
    overflow: 'hidden',
    backgroundColor: colors.primary,
    fontSize: 12,
    fontWeight: '900',
    color: colors.surface,
  },
  noticeBannerText: {
    flex: 1,
    fontSize: 13,
    lineHeight: 18,
    fontWeight: '700',
    color: colors.ink,
  },
  loginScreen: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  loginKeyboard: {
    flex: 1,
  },
  loginContent: {
    flexGrow: 1,
    justifyContent: 'center',
    gap: 22,
    padding: 22,
  },
  loginBrandMark: {
    alignSelf: 'flex-start',
    minHeight: 42,
    justifyContent: 'center',
  },
  loginBrandText: {
    fontSize: 24,
    fontWeight: '900',
    color: colors.ink,
  },
  loginCopy: {
    gap: 8,
  },
  loginEyebrow: {
    fontSize: 12,
    fontWeight: '900',
    color: colors.primary,
    textTransform: 'uppercase',
  },
  loginTitle: {
    fontSize: 38,
    lineHeight: 42,
    fontWeight: '900',
    color: colors.ink,
  },
  loginDescription: {
    fontSize: 15,
    lineHeight: 22,
    color: colors.text,
  },
  loginError: {
    minHeight: 44,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    padding: 12,
    borderRadius: 8,
    backgroundColor: colors.dangerSoft,
  },
  loginErrorText: {
    flex: 1,
    color: colors.dangerDeep,
    fontWeight: '700',
  },
  formBlock: {
    gap: 14,
  },
  inputGroup: {
    gap: 7,
  },
  inputLabel: {
    fontSize: 13,
    fontWeight: '800',
    color: colors.ink,
  },
  textInput: {
    minHeight: 52,
    paddingHorizontal: 14,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 8,
    backgroundColor: colors.surface,
    color: colors.ink,
    fontSize: 16,
  },
  primaryButton: {
    minHeight: 52,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    borderRadius: 8,
    backgroundColor: colors.primary,
  },
  primaryButtonText: {
    color: colors.surface,
    fontSize: 16,
    fontWeight: '900',
  },
  baseUrlText: {
    fontSize: 11,
    lineHeight: 16,
    color: colors.placeholder,
  },
  profileContent: {
    gap: 14,
    padding: 16,
    paddingBottom: 30,
  },
  profileCard: {
    alignItems: 'center',
    padding: 22,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  avatarWrap: {
    width: 76,
    height: 76,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 38,
    backgroundColor: colors.primarySoft,
    overflow: 'hidden',
  },
  avatarImage: {
    width: 76,
    height: 76,
  },
  avatarText: {
    fontSize: 22,
    fontWeight: '900',
    color: colors.primary,
  },
  profileName: {
    marginTop: 14,
    fontSize: 22,
    fontWeight: '900',
    color: colors.ink,
  },
  profileEmail: {
    marginTop: 3,
    fontSize: 14,
    color: colors.muted,
  },
  profileRole: {
    marginTop: 10,
    paddingHorizontal: 10,
    paddingVertical: 5,
    borderRadius: 7,
    backgroundColor: colors.surfaceAlt,
    fontSize: 12,
    fontWeight: '800',
    color: colors.text,
  },
  infoCard: {
    borderRadius: 8,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  infoRow: {
    minHeight: 54,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 16,
    paddingHorizontal: 14,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  infoLabel: {
    fontSize: 13,
    fontWeight: '800',
    color: colors.muted,
  },
  infoValue: {
    flex: 1,
    textAlign: 'right',
    fontSize: 13,
    fontWeight: '700',
    color: colors.ink,
  },
  logoutButton: {
    minHeight: 50,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: colors.danger,
    backgroundColor: colors.dangerSoft,
  },
  logoutButtonText: {
    fontSize: 15,
    fontWeight: '900',
    color: colors.dangerDeep,
  },
  disabled: {
    opacity: 0.7,
  },
  pressed: {
    opacity: 0.72,
  },
});
