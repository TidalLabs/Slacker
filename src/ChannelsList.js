
var blessed = require('blessed');
export default class ChannelsList {

    constructor(screen, api) {
        this.screen = screen;
        this.api = api;
        this.channels = [];
        this.callbacks = {
            onChannelChange: []
        };

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
            const chName = e.getText();
            let channel = null;

            for (const ch of this.channels) {
                if (ch.name === chName) {
                    channel = ch;
                    break;
                }
            }

            this.callbacks.onChannelChange.forEach(callback => {
                callback(channel);
            })
        });

        this.screen.append(this.box);
    }

    onChannelChange(callback) {
        this.callbacks.onChannelChange.push(callback);
    }

    setChannels(channels) {
        this.box.clearItems();

        this.channels = channels.map(ch => {
            if (ch.is_im) {
                ch.name = '@' + this.api.getUserName(ch.user);
            } else if (ch.is_mpim) {
                ch.name = '@' + ch.name
                    .replace('mpdm-', '')
                    .replace('-1', '')
                    .replace(/--/g, ', ');
            } else if (ch.is_channel || ch.is_private) {
                ch.name = '#' + ch.name;
            }
            return ch;
        });

        this.channels
            .filter(ch => ch.is_member || ch.is_im)
            .forEach(ch => {
                if (typeof ch !== 'undefined') {
                    this.box.addItem(ch.name)
                }
            });

        this.screen.render();
    }

    refresh() {
        this.api.getChannels((err, res, body) => {
            this.setChannels(body.channels);
        });
    }

}

module.exports = ChannelsList;