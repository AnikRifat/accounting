{{-- Month grid shared by x-form.date and x-form.date-range; the parent Alpine component provides the state. --}}
<div class="calendar">
    <div class="calendar-head">
        <button type="button" class="calendar-nav" x-on:click="shiftMonth(-1)" aria-label="{{ __('Previous month') }}"><x-icon name="chevron-left" /></button>
        <p class="calendar-title" x-text="monthLabel()" aria-live="polite"></p>
        <button type="button" class="calendar-nav" x-on:click="shiftMonth(1)" aria-label="{{ __('Next month') }}"><x-icon name="chevron-right" /></button>
    </div>
    <div class="calendar-grid" role="grid" x-on:keydown.left.prevent="moveFocus(-1)" x-on:keydown.right.prevent="moveFocus(1)" x-on:keydown.up.prevent="moveFocus(-7)" x-on:keydown.down.prevent="moveFocus(7)" x-on:keydown.page-up.prevent="shiftMonth(-1); moveFocus(0)" x-on:keydown.page-down.prevent="shiftMonth(1); moveFocus(0)">
        <template x-for="weekday in weekdays()" :key="weekday"><span class="calendar-weekday" x-text="weekday"></span></template>
        <template x-for="day in days()" :key="day.iso">
            <button type="button" class="calendar-day" x-bind:data-day="day.iso" x-text="day.day" x-bind:tabindex="day.iso === focused ? 0 : -1"
                x-bind:class="{ 'is-outside': ! day.inMonth, 'is-today': day.today, 'is-selected': isSelected(day.iso), 'is-in-range': inRange(day.iso) }"
                x-bind:aria-pressed="isSelected(day.iso)" x-bind:aria-label="new Date(day.iso + 'T00:00').toLocaleDateString('en-GB', { dateStyle: 'full' })"
                x-on:click="pick(day.iso)" x-on:mouseenter="hover = day.iso" x-on:focus="focused = day.iso; hover = day.iso"></button>
        </template>
    </div>
</div>
