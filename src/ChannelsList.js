
const EventEmitter = require('events').EventEmitter;
const blessed = require('blessed');

// how long before an individual channel history, once fetched,
// should be refreshed (updates unread_count_display but not unread * flag)
const REFRESH_TTL = 3 * 60 * 1000;
// how often should the refresh job, which only refreshes REFRESH_CHANNEL_LIMIT channels, runs
const REFRESH_INTERVAL = 1.5 * 1000;
// how many channels to refresh at a time.
const REFRESH_CHANNEL_LIMIT = 15;

export default class ChannelsList extends EventEmitter {

    constructor(screen, api) {
        super();

        this.selectedChannelId = null;
        this.screen = screen;
        this.api = api;
        this.channels = [];

        this.box = blessed.list({
            parent: this.screen,
            top: 'top',
            left: 'left',
            width: '30%',
            height: '100%',
            label: "Channels",
            tags: true,
            scrollable: true,
            mouse: true,
            keys: true,
            border: {
                type: 'line'
            },
            style: {
                fg: 'white',
                bg: 'black',
                border: {
                    fg: '#f0f0f0'
                },
                hover: {
                    bg: 'green'
                }
            }
        });

        this.box.on('select', (e) => {
            const chName = e.getContent();
            let channel = null;

            for (const index in this.channels) {
                const ch = this.channels[index];
                if (ch.display_name === chName) {
                    channel = ch;
                    this.channels[index].has_unread = false;
                    this.setChannels(this.channels);
                    break;
                }
            }

            this.selectedChannelId = channel.id;
            this.emit('select_channel', channel);
            this.refresh();
        });

        this.screen.append(this.box);

        this.init()

        this.refreshTimer = setInterval(() => { this.refresh(); }, REFRESH_INTERVAL);
    }

    initMessageListener() {

        this.api.on('message', (message) => {

            // increment unread display count
            if (message.type === 'message' && message.channel && message.channel !== this.selectedChannelId) {
                for (const index in this.channels) {
                    if (this.channels[index].id === message.channel) {
                        this.channels[index].has_unread = true;
                        if (typeof this.channels[index].history === 'undefined'
                            || typeof this.channels[index].history.messages === 'undefined'
                        ) {
                            this.channels[index].history = {messages: []};
                        }
                        this.channels[index].history.messages.unshift(message);
                        this.setChannels(this.channels);
                        break;
                    }

                }
            }

        });

    }

    setChannels(channels) {

        this.channels = channels.map(ch => {
            ch = Object.assign({}, ch);
            // ch.history = {unread_count_display: 3};
            ch.display_name = ch.name || '';

            if (ch.is_im) {
                ch.display_name = '@' + this.api.getUserName(ch.user);
            } else if (ch.is_mpim) {
                ch.display_name = '@' + ch.display_name
                    .replace('mpdm-', '')
                    .replace('-1', '')
                    .replace(/--/g, ', ');
            } else if (ch.is_channel || ch.is_private) {
                ch.display_name = '#' + ch.display_name;
            }

            if (
                typeof ch.history !== 'undefined'
                && typeof ch.history.unread_count_display !== 'undefined'
            ) {
                ch.display_name = '(' + ch.history.unread_count_display + ') ' + ch.display_name;
            }

            if (ch.has_unread) {
                ch.display_name = '* ' + ch.display_name + '';
            }

            return ch;
        });

        this.renderChannels();
    }

    renderChannels() {
        // this.box.clearItems();

        this.channels
            .filter(ch => ch.is_member || ch.is_im)
            .sort((a, b) => {
                let ats = -1;
                let bts = -1;

                if (a.history && a.history.messages && a.history.messages[0]) {
                    if (typeof a.history.messages[0].ts === 'undefined') {
                        ats = 0;
                    } else {
                        ats = parseFloat(a.history.messages[0].ts);
                    }
                }

                if (b.history && b.history.messages && b.history.messages[0]) {
                    if (typeof b.history.messages[0].ts === 'undefined') {
                        bts = 0;
                    } else {
                        bts = parseFloat(b.history.messages[0].ts);
                    }
                }

                return ats < bts ? 1 : -1;
            })
            .forEach((ch, i) => {
                if (typeof ch !== 'undefined') {
                    this.box.spliceItem(i,1,ch.display_name)
                }
            });

        this.screen.render();

    }

    fetchAllHistories() {

        // only refresh REFRESH_CHANNEL_LIMIT channels, if they're more than REFRESH_TTL old, every REFRESH_INTERVAL
        let delay = 10;
        let queued = 0;
        const now = Date.now();

        for (const index in this.channels) {
            const channel = this.channels[index];
            const lastUpdate = (channel.history || {}).lastUpdated;
            if (typeof lastUpdate !== 'undefined' && (lastUpdate - now) < REFRESH_TTL) {
                // Not old enough to update yet.
                continue;
            }

            setTimeout(() => {
                this.api.fetchChannelHistory(channel, history => {
                   // check for rate limit
                    if (history.ok) {
                        this.channels[index].history = {...history, lastUpdated: Date.now()};
                        this.setChannels(this.channels);
                    } else {
                        console.log(history);
                    }
                });
            }, delay*queued);

            queued++;

            if (queued > REFRESH_CHANNEL_LIMIT) {
                break;
            }
        }
    }

    refresh() {
        this.fetchAllHistories();
    }

    init() {
        this.api.fetchChannels(channels => {
            this.setChannels(Object.values(channels));
            // todo: commented out, causes selection to jump to top
            // figure out how to re-render list without changing selection
            this.fetchAllHistories();
            this.initMessageListener();
        });
    }

}

module.exports = ChannelsList;