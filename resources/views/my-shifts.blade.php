@extends('layouts.default')

@section('body-class', 'booking-home-body')

@section('header')
  @include('layouts.header-public')
@endsection

@section('main-content')
  <section class="my-shifts-page">
    <div class="my-shifts-layout">
      <article class="my-shifts-card">
        <div class="my-shifts-head">
          <div>
            <p class="my-shifts-eyebrow">Personligt overblik</p>
            <h1>Mine vagter</h1>
          </div>
          <p class="my-shifts-text">
            Her ser du dine kommende offentliggjorte vagter. Afholdte vagter vises ikke.
          </p>
        </div>

        @if (filled($countdownTargetIso))
          <div class="my-shifts-range-label my-shifts-countdown" data-countdown-target="{{ $countdownTargetIso }}">
            <span>{{ $countdownPrefix }}</span>
            <strong data-countdown-value>--</strong>
          </div>
        @endif
        <div class="my-shifts-range-meta">
          {{ $upcomingShiftCount }} kommende vagter
        </div>

        <div class="my-shifts-list-scroll">
          <div class="my-shifts-list">
            @forelse ($upcomingShiftsByDate as $dateKey => $dayShifts)
              @foreach ($dayShifts as $shift)
                @php
                  $shiftDate = $shift->shift_date->locale('da');
                  $weekdayLabel = mb_strtolower((string) $shiftDate->isoFormat('dddd'));
                  $dateLabel = mb_strtolower((string) $shiftDate->isoFormat('D. MMMM'));
                  $shiftTime = substr((string) $shift->starts_at, 0, 5) . ' - ' . substr((string) $shift->ends_at, 0, 5);
                  $shiftNote = trim((string) ($shift->notes ?? ''));
                  $breakLabel = $shift->break_starts_at && $shift->break_ends_at
                    ? 'Pause ' . substr((string) $shift->break_starts_at, 0, 5) . '-' . substr((string) $shift->break_ends_at, 0, 5)
                    : null;
                @endphp
                <div class="my-shift-row{{ $shift->workRoleValue() === \App\Models\UserWorkShift::ROLE_ADMINISTRATION ? ' is-admin' : '' }}">
                  <div class="my-shift-row-main my-shift-row-main-structured">
                    <div class="my-shift-structured-date">
                      <span class="my-shift-structured-weekday">{{ $weekdayLabel }}</span>
                      <span class="my-shift-structured-day">{{ $dateLabel }}</span>
                    </div>
                    <span class="my-shift-structured-divider" aria-hidden="true"></span>
                    <div class="my-shift-structured-right">
                      <strong class="my-shift-structured-time">{{ $shiftTime }}</strong>
                      <span class="my-shift-structured-context">
                        <span>{{ $shift->location?->name ?? 'Lokation' }}</span>
                        <span>{{ $shift->workRoleLabel() }}</span>
                      </span>
                      @if ($breakLabel)
                        <span class="my-shift-structured-break">{{ $breakLabel }}</span>
                      @endif
                      @if ($shiftNote !== '')
                        <div id="my-shift-note-{{ $shift->id }}" class="my-shift-note-source" hidden>{{ $shiftNote }}</div>
                      @endif
                    </div>
                    @if ($shiftNote !== '')
                      <button
                        type="button"
                        class="my-shift-note-toggle"
                        data-note-toggle
                        data-note-target="my-shift-note-{{ $shift->id }}"
                        data-note-date="{{ $weekdayLabel }} {{ $dateLabel }}"
                        title="Vis note"
                      >
                        <span class="my-shift-note-toggle-icon" aria-hidden="true"></span>
                        <span class="sr-only">Vis note</span>
                      </button>
                    @endif
                  </div>
                </div>
              @endforeach
            @empty
              <article class="my-shifts-empty">
                <strong>Ingen kommende vagter</strong>
                <span>Der er ingen offentliggjorte vagter frem i tiden endnu.</span>
              </article>
            @endforelse
          </div>
        </div>
      </article>
    </div>
  </section>

  <dialog class="my-shift-note-modal" data-note-modal>
    <article class="my-shift-note-modal-card">
      <header class="my-shift-note-modal-head">
        <div>
          <h2>Vagtnote</h2>
          <p data-note-modal-date></p>
        </div>
        <button type="button" class="my-shift-note-modal-close" data-note-modal-close aria-label="Luk note">
          ×
        </button>
      </header>
      <p class="my-shift-note-modal-content" data-note-modal-content></p>
    </article>
  </dialog>

  @if (filled($countdownTargetIso))
    <script>
      (function () {
        const countdownRoot = document.querySelector('[data-countdown-target]');
        if (!countdownRoot) {
          return;
        }

        const valueNode = countdownRoot.querySelector('[data-countdown-value]');
        const targetRaw = countdownRoot.getAttribute('data-countdown-target');
        const targetMs = targetRaw ? Date.parse(targetRaw) : NaN;

        if (!valueNode || Number.isNaN(targetMs)) {
          return;
        }

        const formatRemaining = (diffMs) => {
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
        };

        const tick = () => {
          const nowMs = Date.now();
          const diffMs = targetMs - nowMs;
          valueNode.textContent = formatRemaining(diffMs);
        };

        tick();
        setInterval(tick, 1000);
      })();
    </script>
  @endif

  <script>
    (function () {
      const toggles = document.querySelectorAll('[data-note-toggle]');
      const modal = document.querySelector('[data-note-modal]');
      const modalContent = modal?.querySelector('[data-note-modal-content]');
      const modalDate = modal?.querySelector('[data-note-modal-date]');
      const modalCloseButton = modal?.querySelector('[data-note-modal-close]');

      if (!toggles.length || !modal || !modalContent || !modalDate) {
        return;
      }

      const closeModal = () => {
        if (modal instanceof HTMLDialogElement && typeof modal.close === 'function') {
          if (modal.open) {
            modal.close();
          }

          return;
        }

        modal.removeAttribute('open');
      };

      const openModal = (noteText, dateText) => {
        modalContent.textContent = noteText;
        modalDate.textContent = dateText;

        if (modal instanceof HTMLDialogElement && typeof modal.showModal === 'function') {
          if (!modal.open) {
            modal.showModal();
          }

          return;
        }

        modal.setAttribute('open', '');
      };

      toggles.forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) {
          return;
        }

        button.addEventListener('click', () => {
          const targetId = String(button.dataset.noteTarget || '').trim();
          if (!targetId) {
            return;
          }

          const noteSource = document.getElementById(targetId);
          if (!noteSource) {
            return;
          }

          const noteText = noteSource.textContent ? noteSource.textContent.trim() : '';
          if (noteText === '') {
            return;
          }

          const dateText = String(button.dataset.noteDate || '').trim();
          openModal(noteText, dateText);
        });
      });

      if (modalCloseButton instanceof HTMLButtonElement) {
        modalCloseButton.addEventListener('click', closeModal);
      }

      if (modal instanceof HTMLDialogElement) {
        modal.addEventListener('click', (event) => {
          const rect = modal.getBoundingClientRect();
          const inside =
            event.clientX >= rect.left &&
            event.clientX <= rect.right &&
            event.clientY >= rect.top &&
            event.clientY <= rect.bottom;

          if (!inside) {
            closeModal();
          }
        });
      }
    })();
  </script>
@endsection
