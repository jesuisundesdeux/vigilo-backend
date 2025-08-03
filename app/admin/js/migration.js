/* global plan */
/* !GLOBAL plan variable provide by PHP in admin/inc/update.php */

/**
 * Manage migration history
 */
class History {
    constructor(statusLine) {
        this.log = []
        this.statusLine = statusLine
    }

    /**
     * append log message with a leading timestamp
     */
    append(what) {
        const prefix = new Date().toLocaleTimeString('fr-FR') + '&nbsp;'
        this.log.push(prefix + what)
        this.statusLine.innerHTML = what
    }

    /**
     * append error message, need to be handle a bit different message
     */
    error(errno, what) {
        const last = this.log.pop()
        this.log.push([last, what])
        this.statusLine.innerHTML = last + '<pre class="pre-wrap">failed with exit code : ' + errno + '\n' + what + '</pre>'
    }

    /**
     * build html elements from log data structure
     */
    html() {
        const container = document.createElement('div')
        container.setAttribute('id', 'historic-container')
        const section = document.createElement('a')
        section.innerText = 'Historique'
        section.setAttribute('href', '#historic')
        section.setAttribute('data-toggle', 'collapse')
        section.setAttribute('aria-expanded', 'false')
        section.setAttribute('aria-controls', 'historic')
        const list = document.createElement('ol')
        list.setAttribute('id', 'historic')
        list.classList.add('collapse')
        list.classList.add('text-monospace')
        this.log.forEach(log => {
            const line = document.createElement('li')
            if (Array.isArray(log)) {
                line.innerHTML = log[0] + '<ol><li>' + log[1] + '</li></ol>'
            } else {
                line.innerHTML = log
            }
            list.append(line)
        })
        container.append(section)
        container.append(list)
        return container
    }

    /**
     * append or update historic into DOM
     */
    update(target) {
        const already = target.querySelector('#historic-container')
        if (already) {
            already.remove()
        }
        target.append(this.html())
    }
}

const button = document.querySelector('button#upgrade')
const token = button.dataset.token
const upgradable = document.querySelector('#upgradable')
const progress = upgradable.querySelector('.progress')
const progressBar = progress.querySelector('.progress-bar')
const progressWhat = upgradable.querySelector('.progress-what')
const spinner = button.querySelector('#upgrade-spinner')
const history = new History(progressWhat)
const versions = document.querySelector('#versions')
let progressionCounter = 0

// update button click handler
button.addEventListener('click', e => {
    button.setAttribute('disabled', '')
    spinner.removeAttribute('hidden')
    upgradable.removeAttribute('hidden')
    steps()
})

// remove zoom class after its animation finished
versions.addEventListener('animationend', e => {
    versions.classList.remove('zoom')
})

// actions runs when progress bar finished its animation
progressBar.addEventListener('transitionend', e => {
    if (e.propertyName != 'width') return
    if (e.target.style.width === '100%') {
        progressBar.classList.add('bg-success')
        versions.dispatchEvent(new Event('updateversion'))
    }
})

function ending() {
    // button.removeAttribute('disabled')
    spinner.setAttribute('hidden', '')
    history.update(upgradable)
}

function updateProgression(value) {
    progressBar.setAttribute('aria-valuenow', value)
    progressBar.style.width = `${value}%`
    progressBar.innerHTML = value == 0 ? `&nbsp;${value}%` : `${value}%`
}

/**
 * update interface when progress bar animation has finished
 */
function lateUpdater(jsonState) {
    const newVersion = jsonState.split(':').pop()
    versions.addEventListener('updateversion', e => {
        // update versions number
        Array.from(versions.querySelectorAll('span'))
            .forEach(span => {span.innerHTML = newVersion})
        versions.classList.add('zoom')
        // text info paragraph
        Array.from(document.querySelectorAll('.alert>p')).forEach(p => {
            p.style.textDecoration = 'line-through'
            p.style.opacity = 0.75
        })
    })
}

function oneHundred() {
    updateProgression(100)
    history.append('Mise à jour terminée avec succès.')
    progressionCounter = 0
    ending()
}

async function steps() {
    for (let[step, desc] of plan) {
        const progression = Math.floor((progressionCounter * 100) / plan.size)
        updateProgression(progression)
        history.append(`${desc}…`)
        const response = await fetch('/admin/inc/update.php', {
            method: 'POST',
            body: `token=${token}&step=${step}`,
            headers: {'Content-Type': 'application/x-www-form-urlencoded'}
        })
        if (!response.ok) {
            const p = document.createElement('p')
            const ul = document.createElement('ul')
            const content = document.createElement('p')
            const body = await response.text()
            p.innerHTML = 'La mise à jour à échouée.'
            p.classList.value = 'alert-danger p-1'
            ul.classList.add('list-unstyled')
            ;['status', 'statusText', 'redirected', 'url'].forEach(e => {
                const li = document.createElement('li')
                li.innerHTML = `${e}&nbsp;: ${response[e]}`
                ul.append(li)
            })
            progressWhat.innerHTML = ''
            progressWhat.append(p)
            progressWhat.append(ul)
            if (body === '') {
                content.innerHTML = 'Response body was empty.'
            }else{
                content.innerHTML = `Response body&nbsp;:<br />${body}`
            }
            progressWhat.append(content)
            progress.classList.add('bg-danger')
            ending()
            break
        }
        const json = await response.json()
        // handle error message
        if (json.errno != 0) {
            history.error(json.errno, json.errno_msg)
            progress.classList.add('bg-danger')
            ending()
            break
        }
        // no problem with these migration step, go ahead
        progressionCounter++
        // last migration plan step
        if (progressionCounter === plan.size) {
            lateUpdater(json.state)
            oneHundred()
        }
    }
}